<?php
declare(strict_types=1);
namespace App\Game;

use App\Storage\UnicodeText;

final class AskaConfiguration implements ConfigurationAdapter
{
    public function title(\stdClass $configuration): string { return $configuration->{'server name'} ?? 'ASKA'; }
    private const SECRETS = ['password', 'authentication token'];
    private const CHOICES = [
        'keep server world alive' => ['false', 'true'], 'mode' => ['normal', 'custom'],
        'autosave style' => ['every morning', 'disabled', 'every 5 minutes', 'every 10 minutes', 'every 15 minutes', 'every 20 minutes'],
        'region' => ['default', 'asia', 'japan', 'europe', 'south america', 'south korea', 'usa east', 'usa west', 'australia', 'canada east', 'hong kong', 'india', 'turkey', 'united arab emirates', 'usa south central', 'south africa'],
    ];
    public function id(): string { return 'aska'; }
    public function decode(string $bytes): \stdClass
    {
        if (!mb_check_encoding($bytes, 'UTF-8')) { throw new \InvalidArgumentException('Die ASKA-Datei muss UTF-8 enthalten.'); }
        $document = str_starts_with($bytes, "\xef\xbb\xbf") ? substr($bytes, 3) : $bytes;
        $values = new \stdClass();
        foreach (preg_split('/\r\n|\r|\n/', $document) as $line) {
            $line = UnicodeText::trim($line);
            if ($line === '' || $this->comment($line)) { continue; }
            $pair = explode('=', $line, 2); $key = UnicodeText::trim($pair[0]);
            if (count($pair) !== 2 || $key === '' || property_exists($values, $key)) { throw new \InvalidArgumentException('Ungültige oder doppelte ASKA-Einstellung.'); }
            $values->{$key} = UnicodeText::trim($pair[1]);
        }
        return $values;
    }
    private function comment(string $line): bool { return str_starts_with($line, '//') || str_starts_with($line, '#') || str_starts_with($line, ';'); }
    public function encode(\stdClass $configuration, string $original): string
    {
        $bom = str_starts_with($original, "\xef\xbb\xbf") ? "\xef\xbb\xbf" : '';
        $document = $bom === '' ? $original : substr($original, 3);
        $newline = str_contains($document, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $document, -1, PREG_SPLIT_NO_EMPTY);
        $output = ''; $written = [];
        foreach ($lines as $line) {
            $stripped = UnicodeText::trim($line);
            if (!$this->comment($stripped) && str_contains($stripped, '=')) {
                $key = UnicodeText::trim(explode('=', $stripped, 2)[0]);
                if (!property_exists($configuration, $key)) { continue; }
                if (!preg_match('/^(\h*[^=]+?\h*=)[ \t]*(.*?)(\r\n|\n|\r)?$/D', $line, $match)) { throw new \InvalidArgumentException('Ungültige ASKA-Zeile.'); }
                $output .= $match[1].' '.$configuration->{$key}.($match[3] ?? ''); $written[$key] = true;
            } else { $output .= $line; }
        }
        if ($output !== '' && !str_ends_with($output, "\n") && !str_ends_with($output, "\r")) { $output .= $newline; }
        foreach ($configuration as $key => $value) { if (!isset($written[$key])) { $output .= $key.' = '.$value.$newline; } }
        return $bom.$output;
    }
    public function merge(\stdClass $submitted, \stdClass $previous): \stdClass
    {
        $result = clone $submitted;
        foreach (self::SECRETS as $key) { if (!property_exists($result, $key) || $result->{$key} === '') { $result->{$key} = $previous->{$key} ?? ''; } }
        return $result;
    }
    public function publicConfiguration(\stdClass $configuration): \stdClass
    {
        $result = clone $configuration; foreach (self::SECRETS as $key) { $result->{$key} = ''; } return $result;
    }
    public function validate(\stdClass $configuration): void
    {
        foreach ($configuration as $key => $value) {
            $key = (string) $key;
            if ($key === '' || UnicodeText::trim($key) !== $key || str_contains($key, '=') || $this->comment($key)
                || !is_string($value) || strpbrk($key.$value, "\r\n\0") !== false) { throw new \InvalidArgumentException('ASKA-Einstellungen benötigen einzeilige Schlüssel und Textwerte.'); }
        }
        foreach (['server name', 'display name'] as $key) { if (UnicodeText::trim($configuration->{$key} ?? '') === '') { throw new \InvalidArgumentException($key.': Ein Name ist erforderlich.'); } }
        foreach (['steam game port', 'steam query port'] as $key) {
            $port = $configuration->{$key} ?? '';
            if (!preg_match('/^[0-9]+$/D', $port) || (int) $port < 1 || (int) $port > 65535) { throw new \InvalidArgumentException($key.': Erlaubt sind Ports von 1 bis 65535.'); }
        }
        if ($configuration->{'steam game port'} === $configuration->{'steam query port'}) { throw new \InvalidArgumentException('Spielport und Query-Port müssen unterschiedlich sein.'); }
        foreach (self::CHOICES as $key => $allowed) { if (property_exists($configuration, $key) && !in_array($configuration->{$key}, $allowed, true)) { throw new \InvalidArgumentException($key.': Unbekannter Wert für diese ASKA-Version.'); } }
        if (($configuration->{'save id'} ?? '') !== '' && !preg_match('/^[A-Za-z0-9_-]+$/D', $configuration->{'save id'})) { throw new \InvalidArgumentException('Ungültige Save-ID.'); }
    }
    public function validateEdit(\stdClass $configuration, \stdClass $previous, \stdClass $profile): void
    {
        if (($profile->launcher_script ?? '') !== '' && ($configuration->{'save id'} ?? null) !== ($previous->{'save id'} ?? null)) { throw new \DomainException('Der vorhandene ASKA-Starter ist an diese Save-ID gebunden.'); }
    }
    public function validateStart(\stdClass $configuration): void
    {
        if (UnicodeText::trim($configuration->{'authentication token'} ?? '') === '') { throw new \DomainException('Für ASKA fehlt ein Steam-Gameserver-Token.'); }
        if (($configuration->mode ?? '') === 'custom' && UnicodeText::trim($configuration->{'custom game preset path'} ?? '') === '') { throw new \DomainException('Für den eigenen ASKA-Modus fehlt der Pfad zur Spielvorlage.'); }
    }
    public function initial(): \stdClass
    {
        return (object) ['save id' => '', 'display name' => 'ASKA Indy', 'server name' => 'ASKA Indy', 'seed' => '',
            'password' => '', 'authentication token' => '', 'steam game port' => '27015', 'steam query port' => '27016',
            'region' => 'europe', 'keep server world alive' => 'false', 'autosave style' => 'every morning', 'mode' => 'normal', 'custom game preset path' => ''];
    }
}
