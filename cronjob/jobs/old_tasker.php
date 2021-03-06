<?php
set_time_limit(0);

include_once (dirname(__DIR__))."/protected/helper/cron.helper.php";
include_once (dirname(__DIR__))."/../vendor/autoload.php";
include_once (dirname(__DIR__))."/protected/helper/send.helper.php";
include_once (dirname(__DIR__))."/protected/helper/mail.helper.php";
$config = include_once (dirname(__DIR__))."/protected/config.php";
$locals = include_once (dirname(__DIR__))."/protected/locals.php";

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
Rollbar::init($config['ROLLBAR_CONFIG']);

Rollbar::report_message("Tasker is running", 'info');
Rollbar::flush();
$counter    =   0;

if(($pid = cronHelper::lock()) !== FALSE) {
    register_shutdown_function(function (){
        cronHelper::unlock();
    });
    
    
    try{
        $connection =   new AMQPConnection($config['SERVER'], $config['PORT'], $config['USERNAME'], $config['PASSWORD']);
        $channel    =   $connection->channel();
        $channel->queue_declare($config['CHANNEL'], false, true, false, false);
    } catch (Exception $ex) {    
        Rollbar::report_exception($ex);
        exit();
    }
    
    register_shutdown_function(function () use($connection,$channel){
        $channel->close();
        $connection->close();    
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
    $mail       =   $db->Mail;
    $criteria   =   array('$and'=>array(array('status'=>'NEW'),array('confirmation'=>'ACCEPTED')));
    while(($event_obj = $event->findOne($criteria)) != NULL){
        $query  =   array('$and'=>array(
                                        array('types'       =>  array('$in'=>$event_obj['types'])),
                                        array('locations'   =>  array('$in'=>$event_obj['locations'])),
                                        array('categories'  =>  array('$in'=>$event_obj['categories']))));
        
        $email_counter  =   $mail->find($query)->count();
        
        for($i = 0;$i < $email_counter;$i+= $config['PAGINATION_LIMIT']){
            $results    =   $mail->find($query,array('email','code'))->skip($i)->limit($config['PAGINATION_LIMIT']);
            foreach ($results as $result){
                $twig_vars     =   array(
                    'content'           =>  $event_obj['content'],
                    'setting_link'      =>  mailHelper::generateSubscribeLink($result['_id'], $result['code']),
                    'unsubscribe_link'  =>  mailHelper::generateUnsubscribeLink($result['_id'], $result['code']),
                    'id'                =>  $event_obj['_id']
                );
                $subject    = $locals[$config['LANG']]['TASKER_SUBJECT'].$event_obj['subject'];
                $content    = mailHelper::generateContent($twig_vars,'content.html');
                $data       = mailHelper::generateEmailJSON($result['email'], $subject, $content);
                $msg        = new AMQPMessage($data,array('delivery_mode' => 2));
                $channel->basic_publish($msg, '', $config['CHANNEL']);    
                $counter++;
            }
        }
        $event->update(
                array('_id' =>  $event_obj['_id']),
                array('$set'=>  array('status'=>'SENT'))
                );
    }
}

Rollbar::report_message("{$counter} mail generated", 'info');
Rollbar::flush();