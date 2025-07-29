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
                            {--download-only : Only download the binary without running it}
                            {args?* : Arguments to pass to the anycable-go binary}';

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

        // Display a help message about args usage
        if ($this->argument('args') && count($this->argument('args')) > 0) {
            $this->info('Passing additional arguments to anycable-go binary: '.implode(' ', $this->argument('args')));
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

        return $downloadDir.DIRECTORY_SEPARATOR.'anycable-go'.$suffix;
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

        // Get arguments passed to the command
        $args = [$binaryPath];
        $extraArgs = $this->argument('args');

        if (! empty($extraArgs)) {
            $args = array_merge($args, $extraArgs);
        }

        // Set up environment variables
        $env = $_ENV;

        // Set ANYCABLE_BROADCAST_ADAPTER=http if not set
        if (! isset($env['ANYCABLE_BROADCAST_ADAPTER'])) {
            $env['ANYCABLE_BROADCAST_ADAPTER'] = 'http';
        }

        // Set ANYCABLE_PUSHER_APP_ID to REVERB_APP_ID if not set and the latter exists
        if (! isset($env['ANYCABLE_PUSHER_APP_ID']) && isset($env['REVERB_APP_ID'])) {
            $env['ANYCABLE_PUSHER_APP_ID'] = $env['REVERB_APP_ID'];
        }

        // Set ANYCABLE_PUSHER_APP_KEY to REVERB_APP_KEY if not set and the latter exists
        if (! isset($env['ANYCABLE_PUSHER_APP_KEY']) && isset($env['REVERB_APP_KEY'])) {
            $env['ANYCABLE_PUSHER_APP_KEY'] = $env['REVERB_APP_KEY'];
        }

        // Set ANYCABLE_PUSHER_SECRET to REVERB_APP_SECRET if not set and the latter exists
        if (! isset($env['ANYCABLE_PUSHER_SECRET']) && isset($env['REVERB_APP_SECRET'])) {
            $env['ANYCABLE_PUSHER_SECRET'] = $env['REVERB_APP_SECRET'];
        }

        // Set ANYCABLE_PRESETS to broker if not set
        if (! isset($env['ANYCABLE_PRESETS'])) {
            $env['ANYCABLE_PRESETS'] = 'broker';
        }

        // Enable whispering by default
        if (! isset($env['ANYCABLE_STREAMS_WHISPER'])) {
            $env['ANYCABLE_STREAMS_WHISPER'] = 'true';
        }

        $process = new Process($args, null, $env);
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
