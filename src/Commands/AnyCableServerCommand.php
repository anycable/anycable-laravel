<?php

namespace AnyCable\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class AnyCableServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'anycable:server
                            {--binary-path= : Path to the anycable-go binary}
                            {--anycable-version=latest : Version of anycable-go to download if not present}
                            {--download-dir= : Directory to download the binary to}
                            {--download-only : Only download the binary without running it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run anycable-go binary, downloading it if necessary';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $binaryPath = $this->getBinaryPath();

        // If binary doesn't exist, download it
        if (! File::exists($binaryPath)) {
            $this->info('anycable-go binary not found at: '.$binaryPath);
            $binaryPath = $this->downloadBinary();
        }

        if ($this->option('download-only')) {
            $this->info('anycable-go binary downloaded to: '.$binaryPath);

            return 0;
        }

        // Run the binary
        $this->runBinary($binaryPath);

        return 0;
    }

    /**
     * Get the binary path.
     *
     * @return string
     */
    protected function getBinaryPath()
    {
        if ($this->option('binary-path')) {
            return $this->option('binary-path');
        }

        $downloadDir = $this->getDownloadDir();
        $suffix = '';
        if ($this->getPlatform() == 'win') {
            $suffix = '.exe';
        }

        return $downloadDir.DIRECTORY_SEPARATOR.'anycable-go';
    }

    /**
     * Get the download directory.
     *
     * @return string
     */
    protected function getDownloadDir()
    {
        if ($this->option('download-dir')) {
            $dir = $this->option('download-dir');
        } else {
            $dir = storage_path('dist');
        }

        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Download the binary for the current platform.
     *
     * @return string Path to the downloaded binary
     */
    protected function downloadBinary()
    {
        $version = $this->option('anycable-version');
        $platform = $this->getPlatform();
        $arch = $this->getArchitecture();
        $suffix = '';

        if ($platform == 'win') {
            $suffix = '.exe';
        }

        $this->info("Downloading anycable-go version {$version} for {$platform}-{$arch}...");

        if ($version == 'latest') {
            $downloadUrl = "https://github.com/anycable/anycable/releases/latest/download/anycable-go-{$platform}-{$arch}{$suffix}";
        } else {
            $downloadUrl = "https://github.com/anycable/anycable/releases/download/v{$version}/anycable-go-{$platform}-{$arch}{$suffix}";
        }

        $this->info("Download URL: {$downloadUrl}");

        $response = Http::get($downloadUrl);

        if ($response->failed()) {
            throw new RuntimeException("Failed to download anycable-go binary from {$downloadUrl}. HTTP status: ".$response->status());
        }

        $downloadDir = $this->getDownloadDir();
        $binaryPath = $downloadDir.DIRECTORY_SEPARATOR.'anycable-go'.$suffix;

        File::put($binaryPath, $response->body());
        chmod($binaryPath, 0755); // Make binary executable

        $this->info("anycable-go binary downloaded successfully to {$binaryPath}");

        return $binaryPath;
    }

    /**
     * Get the current platform.
     *
     * @return string
     */
    protected function getPlatform()
    {
        $uname = php_uname('s');

        if (stripos($uname, 'darwin') !== false) {
            return 'darwin';
        }

        if (stripos($uname, 'linux') !== false) {
            return 'linux';
        }

        if (stripos($uname, 'win') !== false) {
            return 'win';
        }

        throw new RuntimeException("Unsupported platform: {$uname}");
    }

    /**
     * Get the current architecture.
     *
     * @return string
     */
    protected function getArchitecture()
    {
        $arch = php_uname('m');

        if (in_array($arch, ['x86_64', 'amd64'])) {
            return 'amd64';
        }

        if (in_array($arch, ['aarch64', 'arm64'])) {
            return 'arm64';
        }

        if (in_array($arch, ['i386', 'i686', 'x86'])) {
            return '386';
        }

        throw new RuntimeException("Unsupported architecture: {$arch}");
    }

    /**
     * Run the anycable-go binary.
     *
     * @param  string  $binaryPath
     * @return void
     */
    protected function runBinary($binaryPath)
    {
        $this->info('Starting anycable-go server...');

        $process = new Process([$binaryPath]);
        $process->setTimeout(null);
        $process->setTty(Process::isTtySupported());

        $this->info("Running: {$process->getCommandLine()}");

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException('anycable-go process failed: '.$process->getErrorOutput());
        }
    }
}
