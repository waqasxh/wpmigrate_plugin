<?php
class WPMB_File_Archiver
{
    private $zip;

    public function __construct(ZipArchive $zip)
    {
        $this->zip = $zip;
    }

    public function add_directory($source, $target = '')
    {
        if (!is_dir($source)) {
            return;
        }

        $source = rtrim($source, DIRECTORY_SEPARATOR);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $excludeRoot = realpath(WPMB_Paths::base_dir());

        foreach ($iterator as $file) {
            $fullPath = $file->getPathname();

            if ($excludeRoot && strpos($fullPath, $excludeRoot) === 0) {
                continue;
            }

            $localPath = $this->normalize_path($fullPath, $source, $target);

            if ($file->isDir()) {
                $this->zip->addEmptyDir($localPath);
                continue;
            }

            if (!$this->zip->addFile($fullPath, $localPath)) {
                throw new RuntimeException(sprintf('Failed to archive file %s', $fullPath));
            }
        }
    }

    public static function copy_directory($source, $destination)
    {
        if (!is_dir($source)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    wp_mkdir_p($targetPath);
                }
            } else {
                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                }
                if (!copy($item->getPathname(), $targetPath)) {
                    throw new RuntimeException(sprintf('Unable to copy %s to %s', $item->getPathname(), $targetPath));
                }
            }
        }
    }

    private function normalize_path($path, $source, $target)
    {
        $relative = ltrim(str_replace($source, '', $path), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $base = trim($target, '/');
        return $base ? $base . '/' . $relative : $relative;
    }
}
