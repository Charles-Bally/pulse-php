<?php

declare(strict_types=1);

namespace Pulse\Storage;

use Pulse\Support\Text;

final class StateStore
{
    private string $seen = '';
    private bool $logs = false;
    private int $until = 0;

    public function __construct(private readonly string $path)
    {
    }

    public static function in(string $directory): self
    {
        return new self(rtrim($directory, '/') . '/state.json');
    }

    // L'état a une date de fin : un worker arrêté depuis longtemps coupe tout seul la recopie des journaux.
    public function write(bool $logs, bool $live, int $validSeconds): void
    {
        try {
            LockedFile::replace($this->path, Text::json(['logs' => $logs, 'live' => $live, 'until' => time() + $validSeconds]));
        } catch (\Throwable) {
        }
    }

    // Un simple stat par ligne de journal : le fichier n'est relu que s'il a changé.
    public function logsOn(): bool
    {
        clearstatcache(true, $this->path);
        $stat = @stat($this->path);
        $seen = $stat === false ? '' : "{$stat['ino']}:{$stat['mtime']}:{$stat['size']}";
        if ($seen !== $this->seen) {
            $this->seen = $seen;
            $data = $seen === '' ? null : json_decode((string) LockedFile::read($this->path), true);
            $this->logs = is_array($data) && ($data['logs'] ?? false) === true;
            $this->until = is_array($data) ? (int) ($data['until'] ?? 0) : 0;
        }

        return $this->logs && $this->until >= time();
    }
}
