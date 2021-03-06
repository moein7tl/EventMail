<?php
set_time_limit(0);

include_once (dirname(__DIR__))."/protected/helper/cron.helper.php";
include_once (dirname(__DIR__))."/../vendor/autoload.php";
include_once (dirname(__DIR__))."/protected/helper/send.helper.php";
$config = include_once (dirname(__DIR__))."/protected/config.php";

Rollbar::init($config['ROLLBAR_CONFIG']);

$counter    =   0;

if(($pid = cronHelper::lock()) !== FALSE) {
    register_shutdown_function(function (){
        cronHelper::unlock();
    });

    try{
        $mongo      =   new Mongo($config['DB_STRING']);        
    } catch (Exception $ex) {
        Rollbar::report_exception($ex);
        exit();
    }
    
    register_shutdown_function(function () use($mongo){
        $mongo->close();
    });
    
    $db         =   $mongo->selectDB($config['DB_COLLECTION']);
    $event      =   $db->Event;
    $criteria   =   array('$and'=>array(array('confirmation'=>'ACCEPTED'),
                                        array('$or'=>array(
                                            array('shared'=>array('$exists'=>FALSE)),
                                            array('shared'=>FALSE)
                                        ))));
    
    while($event->find($criteria)->count()){
        $eventObj   =   $event->findOne($criteria);
        sendHelper::sendByPHP($config['EMAIL_TO_TWITTER'], $eventObj['subject'], $eventObj['site']);
        $event->update( array('_id' =>  $eventObj['_id']),array('$set'=>  array('shared'=>TRUE)));
        $counter++;
    }
}

Rollbar::report_message("{$counter} events shared", 'info');
Rollbar::flush();