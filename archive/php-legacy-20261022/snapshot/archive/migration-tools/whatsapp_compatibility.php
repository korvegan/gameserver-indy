<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Instance\InstanceRegistry;
use App\Log\LogText;
use App\Kernel;
use App\Runtime\ProcessObservation;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use App\WhatsApp\Readiness;
use App\WhatsApp\WhatsApp;
use App\WhatsApp\WhatsAppSnapshot;

$root = $argv[1] ?? ''; $input = PrivateFiles::object(PrivateFiles::read($root.'/fixture.json'));
if (!str_starts_with(basename($root),'indy-whatsapp-parity-') || ($input->marker ?? null) !== basename($root)) { throw new RuntimeException('Own WhatsApp fixture required.'); }
$state = $root.'/php-state'; (new SliceRuntime($state,'managed','owner'))->initialize();
foreach (['APP_ENV'=>'test','SLICE_STATE_DIR'=>$state,'SLICE_DRIVER'=>'managed','SLICE_ROLE'=>'owner','INDY_CACHE_DIR'=>$root.'/cache'] as $name=>$value) { $_ENV[$name] = $_SERVER[$name] = $value; putenv($name.'='.$value); }
$kernel = new Kernel('test',true); $kernel->boot(); $container = $kernel->getContainer()->get('test.service_container'); $container->get(InstanceRegistry::class)->import($input->settings); $result = [];
foreach ($input->cases as $case) {
    if ($case->kind === 'tail') { $result[] = LogText::tail($case->path,$case->values); }
    else {
        $status = json_decode(json_encode($case->status,JSON_THROW_ON_ERROR),true,flags:JSON_THROW_ON_ERROR);
        $container->get(ProcessObservation::class)->record($case->id,$status); $snapshot = $container->get(WhatsApp::class)->snapshot($case->id);
        $result[] = ['evidence'=>$snapshot['readiness'],'message'=>$snapshot['message']];
    }
}
$container->get(WhatsAppSnapshot::class)->copy($input->source,$root.'/copy'); echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); $kernel->shutdown();
