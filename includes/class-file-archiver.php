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
            WPMB_Log::write('Directory archiving skipped - source not found', ['source' => $source]);
            return;
        }

        WPMB_Log::write('Starting directory archiving', ['source' => $source, 'target' => $target]);

        $source = rtrim($source, DIRECTORY_SEPARATOR);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $excludeRoot = realpath(WPMB_Paths::base_dir());
        $filesAdded = 0;
        $dirsAdded = 0;
        $filesSkipped = 0;

        foreach ($iterator as $file) {
            $fullPath = $file->getPathname();

            if ($excludeRoot && strpos($fullPath, $excludeRoot) === 0) {
                $filesSkipped++;
                continue;
            }

            $localPath = $this->normalize_path($fullPath, $source, $target);

            if ($file->isDir()) {
                $this->zip->addEmptyDir($localPath);
                $dirsAdded++;
                continue;
            }

            if (!$this->zip->addFile($fullPath, $localPath)) {
                WPMB_Log::write('Failed to add file to archive', ['file' => $fullPath]);
                throw new RuntimeException(sprintf('Failed to archive file %s', $fullPath));
            }
            $filesAdded++;

            // Log progress every 100 files
            if ($filesAdded % 100 === 0) {
                WPMB_Log::write('Archiving progress', [
                    'files_added' => $filesAdded,
                    'dirs_added' => $dirsAdded,
                ]);
            }
        }

        WPMB_Log::write('Directory archiving completed', [
            'files_added' => $filesAdded,
            'dirs_added' => $dirsAdded,
            'files_skipped' => $filesSkipped,
        ]);
    }

    public static function copy_directory($source, $destination)
    {
        if (!is_dir($source)) {
            WPMB_Log::write('Directory copying skipped - source not found', ['source' => $source]);
            return;
        }

        WPMB_Log::write('Starting directory copy', ['source' => $source, 'destination' => $destination]);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $filesCopied = 0;
        $dirsCopied = 0;

        foreach ($iterator as $item) {
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    wp_mkdir_p($targetPath);
                    $dirsCopied++;
                }
            } else {
                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                }
                if (!copy($item->getPathname(), $targetPath)) {
                    WPMB_Log::write('Failed to copy file', [
                        'source' => $item->getPathname(),
                        'destination' => $targetPath,
                    ]);
                    throw new RuntimeException(sprintf('Unable to copy %s to %s', $item->getPathname(), $targetPath));
                }
                $filesCopied++;

                // Log progress every 100 files
                if ($filesCopied % 100 === 0) {
                    WPMB_Log::write('Copy progress', [
                        'files_copied' => $filesCopied,
                        'dirs_created' => $dirsCopied,
                    ]);
                }
            }
        }

        WPMB_Log::write('Directory copy completed', [
            'files_copied' => $filesCopied,
            'dirs_created' => $dirsCopied,
        ]);
    }

    private function normalize_path($path, $source, $target)
    {
        $relative = ltrim(str_replace($source, '', $path), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $base = trim($target, '/');
        return $base ? $base . '/' . $relative : $relative;
    }
}
