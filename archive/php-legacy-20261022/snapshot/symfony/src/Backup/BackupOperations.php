<?php
declare(strict_types=1);
namespace App\Backup;

final class BackupOperations
{
    public const ALL = ['backup', 'backup_verify', 'backup_pin', 'backup_settings', 'backup_restore', 'backup_recover'];
    public static function validate(string $operation, ?\stdClass $payload): void
    {
        $fields = match ($operation) {
            'backup' => [], 'backup_verify' => ['backup_id'], 'backup_pin' => ['backup_id', 'pinned'],
            'backup_settings' => ['automatic_after_stop'], 'backup_restore', 'backup_recover' => ['backup_id', 'token', 'confirmation'],
            default => throw new \InvalidArgumentException('Unbekannter Sicherungsauftrag.'),
        };
        $actual = $payload === null ? [] : array_keys(get_object_vars($payload)); sort($fields); sort($actual);
        if ($fields !== $actual) { throw new \InvalidArgumentException('Sicherungsauftrag enthält fehlende oder unbekannte Felder.'); }
        if (in_array('backup_id', $fields, true) && (!is_string($payload->backup_id) || !preg_match('/^[a-f0-9]{32}$/D', $payload->backup_id))) { throw new \InvalidArgumentException('Ungültige Sicherungs-ID.'); }
        foreach (['pinned', 'automatic_after_stop'] as $field) {
            if (in_array($field, $fields, true) && !is_bool($payload->{$field})) { throw new \InvalidArgumentException('Sicherungseinstellung muss ausdrücklich wahr oder falsch sein.'); }
        }
        if (in_array('token', $fields, true) && (!is_string($payload->token) || !preg_match('/^[a-f0-9]{64}$/D', $payload->token)
            || $payload->confirmation !== 'WIEDERHERSTELLEN')) { throw new \InvalidArgumentException('Vorschau und ausdrückliche Wiederherstellungsbestätigung erforderlich.'); }
    }
}
