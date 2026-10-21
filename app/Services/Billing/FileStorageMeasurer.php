<?php

namespace App\Services\Billing;

use App\Support\PublicStorageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Measures application file storage and attributes it to organizations.
 *
 * 1. Inventory: every file under every configured disk root
 *    (config/usage.php `storage.disks`) is listed with filesystem metadata
 *    only (lstat / storage-provider listing) — no file is ever read.
 *    Symbolic links are not followed; a physical file reachable through
 *    several hard-linked paths is counted once (device + inode).
 * 2. Ownership: every database column that stores an uploaded-file URL is
 *    read (see collectReferences()) and mapped to the owning organization(s)
 *    through verified relationships.
 * 3. Attribution per physical file:
 *    - one owning organization        -> that organization;
 *    - several owning organizations   -> split equally between them
 *      (each is charged its share once, however many of its records point
 *      at the file);
 *    - only platform content (CMS pages, the global amenity/feature icon
 *      library, superadmin avatars) -> "platform shared", billed to no one;
 *    - no reference at all            -> "unattributed", billed to no one.
 *
 * A disk root that can't be read is reported as an error and makes the
 * measurement incomplete; the snapshot then marks file storage as
 * failed/partial instead of reporting zero.
 */
class FileStorageMeasurer
{
    /** Category labels used in the per-organization breakdown. */
    public const CATEGORIES = [
        'product_images' => 'Product images',
        'campsite_images' => 'Campsite images',
        'category_images' => 'Category images',
        'site_map_images' => 'Site map photos',
        'site_map_icons' => 'Site map icons',
        'profile_images' => 'Profile pictures',
        'organization_content' => 'Organization content',
        'cms_media' => 'Site pages (CMS)',
        'icon_library' => 'Amenity & feature icons',
    ];

    /** @var array<string, array{orgs: array<string, true>, platform: bool, category: string}> */
    private array $references = [];

    public function measure(): array
    {
        $this->references = [];
        $errors = [];

        try {
            $this->collectReferences();
        } catch (\Throwable $e) {
            report($e);

            return $this->failed('Could not read file references from the database: '.$e->getMessage());
        }

        $referenceDisk = (string) config('usage.storage.reference_disk', 'public');
        $roots = [];
        $physical = []; // identity => ['size' => int, 'paths' => [disk-relative path => true], 'disk' => string]
        $excluded = ['files' => 0, 'bytes' => 0];
        $skippedLinks = 0;
        $referenceDiskReadable = false;

        foreach ((array) config('usage.storage.disks', ['public']) as $disk) {
            $root = $this->scanDisk($disk, $physical, $excluded, $skippedLinks);
            $roots[] = $root;
            foreach ($root['errors'] as $error) {
                $errors[] = "[{$disk}] {$error}";
            }
            if ($disk === $referenceDisk && $root['status'] !== 'error') {
                $referenceDiskReadable = true;
            }
        }

        if (! $referenceDiskReadable) {
            return $this->failed("The '{$referenceDisk}' disk (where uploads are stored) could not be scanned.", $roots, $errors);
        }

        $organizations = [];
        $summary = [
            'scanned_files' => 0,
            'scanned_bytes' => 0,
            'tenant_bytes' => 0,
            'tenant_files' => 0,
            'tenant_shared_bytes' => 0,
            'tenant_shared_files' => 0,
            'platform_shared_bytes' => 0,
            'platform_shared_files' => 0,
            'unattributed_bytes' => 0,
            'unattributed_files' => 0,
            'excluded_files' => $excluded['files'],
            'excluded_bytes' => $excluded['bytes'],
            'skipped_links' => $skippedLinks,
            'missing_references' => 0,
            'categories' => [],
        ];
        $presentPaths = [];

        foreach ($physical as $file) {
            $size = $file['size'];
            $summary['scanned_files']++;
            $summary['scanned_bytes'] += $size;

            $owners = [];
            $platform = false;
            $category = null;
            if ($file['disk'] === $referenceDisk) {
                foreach (array_keys($file['paths']) as $path) {
                    $presentPaths[$path] = true;
                    $reference = $this->references[$path] ?? null;
                    if (! $reference) {
                        continue;
                    }
                    $owners += $reference['orgs'];
                    $platform = $platform || $reference['platform'];
                    $category ??= $reference['category'];
                }
            }

            if ($owners !== []) {
                $share = $size / count($owners);
                foreach (array_keys($owners) as $orgId) {
                    $organizations[$orgId] ??= ['bytes' => 0.0, 'files' => 0, 'shared_files' => 0, 'categories' => []];
                    $organizations[$orgId]['bytes'] += $share;
                    $organizations[$orgId]['files']++;
                    if (count($owners) > 1) {
                        $organizations[$orgId]['shared_files']++;
                    }
                    $organizations[$orgId]['categories'][$category] = ($organizations[$orgId]['categories'][$category] ?? 0) + $share;
                }
                if (count($owners) > 1) {
                    $summary['tenant_shared_bytes'] += $size;
                    $summary['tenant_shared_files']++;
                } else {
                    $summary['tenant_bytes'] += $size;
                    $summary['tenant_files']++;
                }
                $summary['categories'][$category] = ($summary['categories'][$category] ?? 0) + $size;
            } elseif ($platform) {
                $summary['platform_shared_bytes'] += $size;
                $summary['platform_shared_files']++;
                $summary['categories'][$category] = ($summary['categories'][$category] ?? 0) + $size;
            } else {
                $summary['unattributed_bytes'] += $size;
                $summary['unattributed_files']++;
            }
        }

        foreach (array_keys($this->references) as $path) {
            if (! isset($presentPaths[$path])) {
                $summary['missing_references']++;
            }
        }

        foreach ($organizations as $orgId => $data) {
            $organizations[$orgId]['bytes'] = (int) round($data['bytes']);
            $organizations[$orgId]['categories'] = array_map(fn ($bytes) => (int) round($bytes), $data['categories']);
            ksort($organizations[$orgId]['categories']);
        }
        ksort($summary['categories']);

        return [
            'available' => true,
            'complete' => $errors === [],
            'errors' => $errors,
            'roots' => $roots,
            'organizations' => $organizations,
            'summary' => $summary,
        ];
    }

    /**
     * Every database column that stores an uploaded file, with the
     * relationship that decides who owns it. Add new upload columns here.
     */
    private function collectReferences(): void
    {
        // Product and campsite photos: engage_products.images (JSON list of
        // {url, ...}); soft-deleted products still own their files on disk.
        DB::table('engage_products')
            ->select(['id', 'engage_organization_location_id', 'product_rental_id', 'images', 'ghl_image_url'])
            ->orderBy('id')
            ->chunk(500, function ($products) {
                foreach ($products as $product) {
                    $category = $product->product_rental_id ? 'campsite_images' : 'product_images';
                    foreach ($this->urlsIn($this->decode($product->images)) as $url) {
                        $this->addOrg($url, $product->engage_organization_location_id, $category);
                    }
                    $this->addOrg($product->ghl_image_url, $product->engage_organization_location_id, $category);
                }
            });

        DB::table('engage_categories')->whereNotNull('image')->select(['image', 'engage_organization_location_id'])
            ->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $this->addOrg($row->image, $row->engage_organization_location_id, 'category_images');
                }
            });

        DB::table('site_maps')->whereNotNull('image_url')->select(['image_url', 'engage_organization_location_id'])
            ->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $this->addOrg($row->image_url, $row->engage_organization_location_id, 'site_map_images');
                }
            });

        DB::table('site_map_icon_types')->whereNotNull('image_url')->select(['image_url', 'engage_organization_location_id'])
            ->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $this->addOrg($row->image_url, $row->engage_organization_location_id, 'site_map_icons');
                }
            });

        // Organization profile content (rich-text business information may embed images).
        DB::table('engage_organization_locations')->whereNotNull('business_information')->select(['id', 'business_information'])
            ->orderBy('id')->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    foreach ($this->urlsIn($this->decode($row->business_information)) as $url) {
                        $this->addOrg($url, $row->id, 'organization_content');
                    }
                }
            });

        // Profile pictures. Staff belong to the organizations they're linked
        // to; a customer login belongs to every organization its customer
        // record is linked to; anyone with neither (the superadmin) is platform.
        $staffOrgs = $this->groupedLinks('engage_users_locations', 'user_id');
        $customerOrgs = $this->groupedLinks('engage_customers_locations', 'customer_id');
        DB::table('engage_users')->whereNotNull('avatar_url')->select(['id', 'avatar_url', 'customer_id'])
            ->orderBy('id')->chunk(500, function ($users) use ($staffOrgs, $customerOrgs) {
                foreach ($users as $user) {
                    $orgs = array_unique(array_merge(
                        $staffOrgs[$user->id] ?? [],
                        $user->customer_id ? ($customerOrgs[$user->customer_id] ?? []) : []
                    ));
                    if ($orgs === []) {
                        $this->addPlatform($user->avatar_url, 'profile_images');
                    }
                    foreach ($orgs as $orgId) {
                        $this->addOrg($user->avatar_url, $orgId, 'profile_images');
                    }
                }
            });

        // Platform-wide content: CMS pages (logos, backgrounds, videos,
        // thumbnails) and the shared amenity/feature icon library.
        foreach (DB::table('cms_pages')->select('content')->get() as $page) {
            foreach ($this->urlsIn($this->decode($page->content)) as $url) {
                $this->addPlatform($url, 'cms_media');
            }
        }
        foreach (['amenities', 'features'] as $table) {
            foreach (DB::table($table)->whereNotNull('icon')->pluck('icon') as $icon) {
                $this->addPlatform($icon, 'icon_library');
            }
        }
    }

    /** @return array<string|int, list<string>> */
    private function groupedLinks(string $table, string $column): array
    {
        $map = [];
        foreach (DB::table($table)->select([$column, 'engage_organization_location_id'])->cursor() as $row) {
            $map[$row->{$column}][] = $row->engage_organization_location_id;
        }

        return $map;
    }

    private function addOrg(?string $url, ?string $orgId, string $category): void
    {
        $path = $this->normalize($url);
        if ($path === null) {
            return;
        }
        $this->references[$path] ??= ['orgs' => [], 'platform' => false, 'category' => $category];
        if ($orgId) {
            $this->references[$path]['orgs'][$orgId] = true;
        } else {
            $this->references[$path]['platform'] = true;
        }
    }

    private function addPlatform(?string $url, string $category): void
    {
        $path = $this->normalize($url);
        if ($path === null) {
            return;
        }
        $this->references[$path] ??= ['orgs' => [], 'platform' => false, 'category' => $category];
        $this->references[$path]['platform'] = true;
    }

    /** Disk-relative path of one of our own uploads, or null for an external / empty value. */
    private function normalize(?string $url): ?string
    {
        if (! is_string($url) || ! PublicStorageUrl::isOwnStoragePath($url)) {
            return null;
        }
        $path = rawurldecode(PublicStorageUrl::diskRelativePath($url));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }

    /**
     * Every string anywhere in a decoded JSON value that points at our own
     * storage, including URLs embedded inside HTML (rich-text content).
     *
     * @return list<string>
     */
    private function urlsIn(mixed $value): array
    {
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }

                return;
            }
            if (! is_string($node) || ! str_contains($node, '/storage/')) {
                return;
            }
            if (PublicStorageUrl::isOwnStoragePath($node) && ! preg_match('/\s|</', $node)) {
                $found[] = $node;

                return;
            }
            if (preg_match_all('~(?:https?://[^\s"\'<>()]+)?/storage/[^\s"\'<>()?#]+~i', $node, $matches)) {
                foreach ($matches[0] as $match) {
                    $found[] = $match;
                }
            }
        };
        $walk($value);

        return array_values(array_unique($found));
    }

    /**
     * Lists one disk. Local disks: walk the root with lstat, never following
     * symlinks and never leaving the root. Other drivers: the storage
     * provider's listing metadata (file size), never downloading files.
     */
    private function scanDisk(string $disk, array &$physical, array &$excluded, int &$skippedLinks): array
    {
        $config = config("filesystems.disks.{$disk}");
        $result = ['disk' => $disk, 'driver' => $config['driver'] ?? null, 'files' => 0, 'bytes' => 0, 'status' => 'ok', 'errors' => []];

        if (! is_array($config)) {
            $result['status'] = 'error';
            $result['errors'][] = 'Disk is not configured in config/filesystems.php.';

            return $result;
        }

        if (($config['driver'] ?? null) !== 'local') {
            return $this->scanRemoteDisk($disk, $result, $physical, $excluded);
        }

        $root = realpath((string) ($config['root'] ?? ''));
        $result['root'] = $config['root'] ?? null;
        if ($root === false || ! is_dir($root)) {
            // An unused disk whose folder was never created holds nothing.
            $result['status'] = 'empty';

            return $result;
        }
        if (! is_readable($root)) {
            $result['status'] = 'error';
            $result['errors'][] = 'Root folder is not readable.';

            return $result;
        }

        $stack = [$root];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $handle = @opendir($dir);
            if ($handle === false) {
                $result['status'] = 'partial';
                $result['errors'][] = 'Folder could not be read: '.$this->relative($root, $dir);

                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir.DIRECTORY_SEPARATOR.$entry;
                $stat = @lstat($full);
                if ($stat === false) {
                    $result['status'] = 'partial';
                    $result['errors'][] = 'File could not be read: '.$this->relative($root, $full);

                    continue;
                }
                $type = $stat['mode'] & 0170000;
                if ($type === 0120000) { // symbolic link: never followed
                    $skippedLinks++;

                    continue;
                }
                if ($type === 0040000) {
                    $stack[] = $full;

                    continue;
                }
                if ($type !== 0100000) {
                    continue; // sockets, devices, fifos
                }

                $relative = $this->relative($root, $full);
                if ($this->isExcluded($entry)) {
                    $excluded['files']++;
                    $excluded['bytes'] += $stat['size'];

                    continue;
                }

                $identity = "{$disk}:{$stat['dev']}:{$stat['ino']}";
                if (! isset($physical[$identity])) {
                    $physical[$identity] = ['size' => (int) $stat['size'], 'paths' => [], 'disk' => $disk];
                    $result['files']++;
                    $result['bytes'] += (int) $stat['size'];
                }
                $physical[$identity]['paths'][$relative] = true;
            }
            closedir($handle);
        }

        // Keep the stored error list readable if a large tree is unreadable.
        if (count($result['errors']) > 20) {
            $more = count($result['errors']) - 20;
            $result['errors'] = array_slice($result['errors'], 0, 20);
            $result['errors'][] = "...and {$more} more.";
        }

        return $result;
    }

    private function scanRemoteDisk(string $disk, array $result, array &$physical, array &$excluded): array
    {
        try {
            foreach (Storage::disk($disk)->listContents('', true) as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                $size = (int) ($item->fileSize() ?? 0);
                if ($this->isExcluded(basename($item->path()))) {
                    $excluded['files']++;
                    $excluded['bytes'] += $size;

                    continue;
                }
                $physical["{$disk}:{$item->path()}"] = ['size' => $size, 'paths' => [$item->path() => true], 'disk' => $disk];
                $result['files']++;
                $result['bytes'] += $size;
            }
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['errors'][] = 'Listing failed: '.$e->getMessage();
        }

        return $result;
    }

    private function isExcluded(string $name): bool
    {
        if (in_array($name, (array) config('usage.storage.excluded_names', []), true)) {
            return true;
        }
        foreach ((array) config('usage.storage.excluded_patterns', []) as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $root, string $full): string
    {
        return ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
    }

    private function failed(string $error, array $roots = [], array $errors = []): array
    {
        return [
            'available' => false,
            'complete' => false,
            'errors' => array_values(array_unique(array_merge([$error], $errors))),
            'roots' => $roots,
            'organizations' => [],
            'summary' => null,
        ];
    }
}
