<?php

namespace App\Console\Commands;

use App\Webapp;
use Carbon\Carbon;
use Clue\React\Ami\ActionSender;
use Clue\React\Ami\Client;
use Clue\React\Ami\Protocol\Event;
use Clue\React\Ami\Protocol\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Console\Command;
use Clue\React\Ami\Factory;
use Illuminate\Support\Str;
use Webpatser\Uuid\Uuid;

class DaemonProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'daemon:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to process events';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $url = 'http://server/anicrm/modules/PBXManager/callbacks/PBXManager.php';

        $factory->createClient('vtiger:vtiger123@172.16.0.209')->then(
            function (Client $client) use ($loop, $url) {
                $this->info('Client connected');
                $this->info('Press Ctrl + C to exit.');
                $this->info('Starting event listener...');

                $sender = new ActionSender($client);
                $sender->events(true);

                $client->on('close', function () {
                    $this->info('Connection closed');
                });

                $client->on('event', function (Event $event) use ($url) {
                    //$this->info('Event: ' . $event->getName());
                    if ($event->getName() == "Bridge" && $event->getFieldValue('bridgestate') == "Link") {
                        if (str_contains($event->getFieldValue('Channel1'), 'TCL') && !str_contains($event->getFieldValue('Channel2'), 'TCL')) {
                            // This is incoming
                            $this->info(json_encode($event->getFields()));
                            $from_number = $event->getFieldValue('CallerID1');
                            $record = Webapp::create([
                                'uid' => Uuid::generate()->string,
                                'uniqueid1' => $event->getFieldValue('Uniqueid1'),
                                'uniqueid2' => $event->getFieldValue('Uniqueid2'),
                                'channel1' => $event->getFieldValue('Channel1'),
                                'channel2' => $event->getFieldValue('Channel2'),
                                'event' => $event->getFieldValue('event'),
                                'direction' => 'Incoming',
                                'from_number' => $from_number,
                                'to_number' => $event->getFieldValue('Callerid2'),
                                'starttime' => Carbon::now()->format('Y-m-d H:i:s'),
                                'bridged' => true,
                                'state' => $event->getFieldValue('Bridgestate'),
                            ]);

                            $httpclient = new \GuzzleHttp\Client();
                            $_url = "";
                            $params = [
                                /*'query' => [
                                    'vtigersignature' => 'abdullah',
                                    'callstatus' => $record->direction,
                                    'callerIdNumber' => $record->to_number,
                                    'customerNumber' => $record->from_number,
                                    'SourceUUID' => $record->uid
                                ],*/
                                'on_stats' => function (TransferStats $stats) use (&$_url) {
                                    $_url = $stats->getEffectiveUri();
                                }
                            ];
                            $response = $httpclient->get($url . "?vtigersignature=abdullah&callstatus=$record->direction&callerIdNumber=$record->to_number&customerNumber=$record->from_number&SourceUUID=$record->uid", $params);

                            $this->info(dump($response->getBody()));
                            //$this->info(dump($_url));
                        }
                    } elseif ($event->getName() == "Hangup") {
                        $this->info(json_encode($event->getFields()));
                        $record = Webapp::where([
                            ['channel2', $event->getFieldValue('Channel')],
                            ['uniqueid2', $event->getFieldValue('Uniqueid')]
                        ])->first();
                        //$this->info(serialize($record));
                        if(!is_null($record)) {
                            $endtime = Carbon::now();
                            $record->endtime = $endtime->format('Y-m-d H:i:s');
                            $record->totalduration = $endtime->diffInSeconds(Carbon::parse($record->starttime));
                            $record->callcause = $event->getFieldValue('Cause-txt');
                            $record->save();

                            $httpclient = new \GuzzleHttp\Client();
                            $response = $httpclient->get($url."?vtigersignature=abdullah&callstatus=Hangup&callUUID=$record->uid&causetxt=$record->callcause&HangupCause=$record->callcause&EndTime=$record->endtime&Duration=$record->totalduration");
                            $this->warn(dump($response->getBody()));
                        }
                    }

                });
            },
                function (\Exception $e) {
                    $this->error('Connection error: ' . $e->getMessage());
                }
            );

        $loop->run();

        return 0;
    }
}
