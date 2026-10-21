<?php
declare(strict_types=1);
namespace App\Game;

use App\Storage\PrivateFiles;
use App\Storage\UnicodeText;

final class EnshroudedConfiguration implements ConfigurationAdapter
{
    public function title(\stdClass $configuration): string { return $configuration->name ?? 'Enshrouded'; }
    public function id(): string { return 'enshrouded'; }
    public function decode(string $bytes): \stdClass { return PrivateFiles::object($bytes); }
    public function encode(\stdClass $configuration, string $original): string { return PrivateFiles::json($configuration); }
    public function merge(\stdClass $submitted, \stdClass $previous): \stdClass { return $submitted; }
    public function publicConfiguration(\stdClass $configuration): \stdClass { return $configuration; }
    public function validateEdit(\stdClass $configuration, \stdClass $previous, \stdClass $profile): void {}
    public function validateStart(\stdClass $configuration): void {}
    public function validate(\stdClass $configuration): void
    {
        $values = get_object_vars($configuration);
        foreach (['name', 'saveDirectory', 'logDirectory', 'ip', 'gameSettingsPreset'] as $key) {
            if (!is_string($values[$key] ?? null) || UnicodeText::trim($values[$key]) === '') { throw new \InvalidArgumentException($key.': Ein nicht leerer Text ist erforderlich.'); }
        }
        foreach (['slotCount' => [1, 16], 'queryPort' => [1, 65535]] as $key => [$low, $high]) {
            $this->integer($values[$key] ?? null, $low, $high, $key);
        }
        if (filter_var($values['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { throw new \InvalidArgumentException('ip: Ungültige IPv4-Adresse.'); }
        if (!in_array($values['gameSettingsPreset'], ['Default', 'Relaxed', 'Hard', 'Survival', 'Custom'], true)) { throw new \InvalidArgumentException('Unbekannte Schwierigkeitsvorlage.'); }
        foreach (['enableVoiceChat', 'enableTextChat'] as $key) { $this->boolean($values, $key); }
        if (array_key_exists('voiceChatMode', $values) && !in_array($values['voiceChatMode'], ['Proximity', 'Global'], true)) { throw new \InvalidArgumentException('Unbekannter Sprachchat-Modus.'); }
        if (array_key_exists('gameSettings', $values) && !$values['gameSettings'] instanceof \stdClass) { throw new \InvalidArgumentException('gameSettings muss ein Objekt sein.'); }
        $settings = get_object_vars($values['gameSettings'] ?? new \stdClass());
        if (array_key_exists('flameLevelFogRemoval', $settings)) { $this->integer($settings['flameLevelFogRemoval'], 0, 20, 'gameSettings.flameLevelFogRemoval'); }
        if (array_key_exists('randomSpawnerAmount', $settings) && !in_array($settings['randomSpawnerAmount'], ['Few', 'Normal', 'Automatic', 'Many', 'Extreme'], true)) { throw new \InvalidArgumentException('Unbekannte Gegneranzahl.'); }
        if (array_key_exists('tags', $values) && (!is_array($values['tags']) || array_filter($values['tags'], fn ($tag) => !is_string($tag)))) { throw new \InvalidArgumentException('tags muss eine Liste von Texten sein.'); }
        if (!is_array($values['userGroups'] ?? null) || !$values['userGroups']) { throw new \InvalidArgumentException('Mindestens eine Benutzergruppe ist erforderlich.'); }
        $names = [];
        foreach ($values['userGroups'] as $group) {
            if (!$group instanceof \stdClass || !is_string($group->name ?? null) || UnicodeText::trim($group->name) === '' || !is_string($group->password ?? null)) { throw new \InvalidArgumentException('Jede Benutzergruppe benötigt Namen und Passwortfeld.'); }
            $name = mb_convert_case($group->name, MB_CASE_FOLD, 'UTF-8');
            if (in_array($name, $names, true)) { throw new \InvalidArgumentException('Benutzergruppen benötigen eindeutige Namen.'); }
            $names[] = $name;
            foreach (['canKickBan', 'canAccessInventories', 'canEditWorld', 'canEditBase', 'canExtendBase'] as $key) { $this->boolean(get_object_vars($group), $key); }
            $this->integer($group->reservedSlots ?? 0, 0, $values['slotCount'], 'reservedSlots');
            if (property_exists($group, 'reservedSlots') && $group->reservedSlots === null) { throw new \InvalidArgumentException('reservedSlots muss eine ganze Zahl sein.'); }
        }
        PrivateFiles::json($configuration); // Reject non-finite/non-JSON values before any backup/write.
    }
    private function integer(mixed $value, int $low, int $high, string $key): void
    {
        if (!is_int($value) || $value < $low || $value > $high) { throw new \InvalidArgumentException($key.': Erlaubt sind ganze Zahlen von '.$low.' bis '.$high.'.'); }
    }
    private function boolean(array $values, string $key): void
    {
        if (array_key_exists($key, $values) && !is_bool($values[$key])) { throw new \InvalidArgumentException($key.': Erlaubt sind true oder false.'); }
    }
    public function initial(): \stdClass
    {
        $groups = [];
        foreach (['Admin', 'Friend', 'Guest', 'Visitor'] as $name) {
            $groups[] = (object) ['name' => $name, 'password' => rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='),
                'canKickBan' => $name === 'Admin', 'canAccessInventories' => in_array($name, ['Admin', 'Friend'], true),
                'canEditWorld' => $name !== 'Visitor', 'canEditBase' => in_array($name, ['Admin', 'Friend'], true),
                'canExtendBase' => $name === 'Admin', 'reservedSlots' => 0];
        }
        return (object) ['name' => 'Enshrouded Indy', 'saveDirectory' => './savegame', 'logDirectory' => './logs',
            'ip' => '0.0.0.0', 'queryPort' => 15637, 'slotCount' => 16, 'tags' => ['German'],
            'voiceChatMode' => 'Proximity', 'enableVoiceChat' => false, 'enableTextChat' => false,
            'gameSettingsPreset' => 'Default', 'userGroups' => $groups, 'bannedAccounts' => [],
            'gameSettings' => (object) ['flameLevelFogRemoval' => 11, 'randomSpawnerAmount' => 'Automatic']];
    }
}
