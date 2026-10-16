<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

// Invoked by the versioned cross-language comparison with synthetic data only.
$cases = json_decode(stream_get_contents(STDIN), false, flags: JSON_THROW_ON_ERROR); $result = [];
foreach ($cases as $case) {
    $adapter = match ($case->game) { 'enshrouded' => new App\Game\EnshroudedConfiguration(), 'aska' => new App\Game\AskaConfiguration() };
    try {
        $original = base64_decode($case->original, true);
        $previous = $adapter->decode($original);
        $configuration = $adapter->merge($case->config, $previous); $adapter->validate($configuration);
        $adapter->validateEdit($configuration, $previous, $case->profile);
        if ($case->start) { $adapter->validateStart($configuration); }
        $result[] = ['valid' => true, 'public' => $adapter->publicConfiguration($configuration),
            'encoded' => base64_encode($adapter->encode($configuration, $original))];
    } catch (InvalidArgumentException | DomainException | JsonException) { $result[] = ['valid' => false]; }
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
