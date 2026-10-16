<?php
declare(strict_types=1);
namespace App\Dashboard;

use App\Access\Principal;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Integration\Connections;
use App\Integration\Discord;
use App\Integration\SteamUpdates;
use App\Runtime\ProcessObservation;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** Shared read models only; neither native process queries nor external I/O. */
final class ServerOverview
{
    public function __construct(private readonly InstanceRegistry $registry,private readonly GameCatalog $games,
        private readonly NativePaths $paths,private readonly ProcessObservation $observations,private readonly SteamUpdates $updates,
        private readonly Connections $connections,private readonly Discord $discord,private readonly SliceRuntime $runtime) {}
    public function view(Principal $actor,string $id): array
    {
        $profile = $this->registry->get($id); $schema = $this->games->schema($profile->game); $status = $this->observations->view($id);
        unset($status['user_profile']); $directory = $this->paths->local($profile->server_directory);
        $status = [...$status,'server_id'=>$id,'game'=>$profile->game,'game_label'=>$schema->label,'profile_label'=>$profile->label,
            'installed'=>is_file($directory.'/'.$schema->executable),'configured'=>is_file($this->registry->configPath($profile)),
            'external_launcher'=>($profile->launcher_script ?? '') !== '',
            'recovery_required'=>is_file($this->runtime->directory.'/data/world-backups/'.$id.'/restore-pending.json')];
        $summary = ['title'=>$profile->label,'metrics'=>[]]; $error = null; $connection = null;
        try {
            $config = $this->games->adapter($profile->game)->decode(PrivateFiles::read($this->registry->configPath($profile)));
            $summary['title'] = $this->games->adapter($profile->game)->title($config);
            foreach ($schema->metrics ?? [] as $metric) {
                $value = $config->{$metric->key} ?? null; $value = is_scalar($value) ? $value : null;
                $summary['metrics'][] = ['label'=>$metric->label,'value'=>$metric->options->{(string) $value} ?? $value];
            }
            $connection = $this->connections->view($id);
        } catch (\Throwable) { $error = 'Die Spielkonfiguration ist nicht lesbar. Andere Profile bleiben erreichbar.'; }
        if ($actor->allows('config.read',$id)) {
            $status['directory'] = $profile->server_directory; $discord = $this->discord->view($actor,$id);
            $status['discord_configured'] = $discord['configured']; $status['auto_notify'] = $discord['auto_notify'];
        } else {
            $status = array_intersect_key($status,array_flip(['server_id','game','game_label','profile_label','installed','configured','running','starting','known','recovery_required']));
        }
        return ['server_id'=>$id,'status'=>$status,'summary'=>$summary,'config_error'=>$error,'connection'=>$connection,
            'update'=>$this->updates->view($id,$status)];
    }
}
