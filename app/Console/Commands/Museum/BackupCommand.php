<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\Media;
use App\Models\Museum\RawDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Versioned backups: database dump + manifest (sha256 of every raw document and media file)
 * per run, and a content-addressed object store for files (each unique file stored once,
 * never overwritten). Old run folders expire after the retention period; objects are kept.
 */
class BackupCommand extends Command
{
    protected $signature = 'museum:backup {--with-files : Copy raw documents and media into the content-addressed object store} {--no-prune}';

    protected $description = 'Back up the museum database, metadata manifest and (optionally) files to the backup disk';

    public function handle(): int
    {
        $disk = Storage::disk(config('museum.storage.backup_disk'));
        $stamp = now()->format('Y-m-d_His');
        $dir = 'runs/'.$stamp;

        $dbFile = $this->dumpDatabase();
        $disk->put($dir.'/database.'.pathinfo($dbFile, PATHINFO_EXTENSION), fopen($dbFile, 'r'));
        @unlink($dbFile);

        $manifest = ['created_at' => now()->toIso8601String(), 'app' => config('app.name'), 'raw_documents' => [], 'media' => [], 'tables' => []];
        foreach (DB::connection()->getSchemaBuilder()->getTableListing() as $t) {
            $t = is_array($t) ? ($t['name'] ?? reset($t)) : $t;
            $t = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
            if (str_starts_with($t, 'museum_')) {
                $manifest['tables'][$t] = DB::table($t)->count();
            }
        }
        $copied = 0;
        RawDocument::query()->orderBy('id')->chunk(500, function ($rows) use (&$manifest, &$copied, $disk) {
            foreach ($rows as $r) {
                $manifest['raw_documents'][] = ['id' => $r->id, 'disk' => $r->disk, 'path' => $r->path, 'sha256' => $r->sha256, 'size' => $r->size_bytes, 'url' => $r->url];
                $copied += $this->copyObject($disk, $r->disk, $r->path, $r->sha256);
                if ($r->text_path) {
                    $copied += $this->copyObject($disk, $r->disk, $r->text_path, null);
                }
            }
        });
        Media::withTrashed()->orderBy('id')->chunk(500, function ($rows) use (&$manifest, &$copied, $disk) {
            foreach ($rows as $m) {
                $manifest['media'][] = ['uuid' => $m->uuid, 'disk' => $m->disk, 'path' => $m->path, 'sha256' => $m->sha256, 'size' => $m->size_bytes, 'license' => $m->license];
                $copied += $this->copyObject($disk, $m->disk, $m->path, $m->sha256);
            }
        });
        $disk->put($dir.'/manifest.json.gz', gzencode(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $this->info("backup {$dir}: ".count($manifest['raw_documents']).' raw, '.count($manifest['media'])." media, {$copied} new objects");

        if (! $this->option('no-prune')) {
            $cutoff = now()->subDays(config('museum.backup.retention_days'))->format('Y-m-d_His');
            foreach ($disk->directories('runs') as $run) {
                if (basename($run) < $cutoff) {
                    $disk->deleteDirectory($run);
                    $this->line('pruned '.$run);
                }
            }
        }

        return self::SUCCESS;
    }

    private function copyObject($backup, string $srcDisk, string $path, ?string $sha): int
    {
        if (! $this->option('with-files')) {
            return 0;
        }
        $src = Storage::disk($srcDisk);
        if (! $src->exists($path)) {
            return 0;
        }
        $sha ??= hash('sha256', $src->get($path));
        $target = 'objects/'.substr($sha, 0, 2).'/'.$sha;
        if ($backup->exists($target)) {
            return 0;
        }
        $backup->put($target, $src->readStream($path));

        return 1;
    }

    private function dumpDatabase(): string
    {
        $conn = config('database.connections.'.config('database.default'));
        $tmp = tempnam(sys_get_temp_dir(), 'museum-db-');
        switch ($conn['driver']) {
            case 'sqlite':
                $db = $conn['database'] === ':memory:' ? null : $conn['database'];
                if ($db) {
                    DB::statement('VACUUM INTO ?', [$tmp.'.sqlite']);
                    @unlink($tmp);

                    return $tmp.'.sqlite';
                }
                file_put_contents($tmp.'.txt', 'in-memory database: nothing to dump');

                return $tmp.'.txt';
            case 'mysql':
            case 'mariadb':
                $p = new Process([config('museum.backup.mysqldump'), '--single-transaction', '--quick', '--routines', '--hex-blob',
                    '-h', $conn['host'], '-P', (string) $conn['port'], '-u', $conn['username'], $conn['database']],
                    null, ['MYSQL_PWD' => (string) $conn['password']]);
                $p->setTimeout(7200)->mustRun();
                file_put_contents($tmp.'.sql.gz', gzencode($p->getOutput(), 6));
                @unlink($tmp);

                return $tmp.'.sql.gz';
            case 'pgsql':
                $p = new Process(['pg_dump', '-Fc', '-h', $conn['host'], '-p', (string) $conn['port'], '-U', $conn['username'], $conn['database']],
                    null, ['PGPASSWORD' => (string) $conn['password']]);
                $p->setTimeout(7200)->mustRun();
                file_put_contents($tmp.'.dump', $p->getOutput());
                @unlink($tmp);

                return $tmp.'.dump';
        }
        throw new \RuntimeException('Unsupported database driver for backup: '.$conn['driver']);
    }
}
