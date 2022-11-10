<?php
/** Cinema Task
* Version			: 4.1.0
* Package			: Joomla 4.x
* copyright 		: Copyright (C) 2022 ConseilGouz. All rights reserved.
* license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
*
*/

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Date\Date;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

class PlgTaskCinema extends CMSPlugin implements SubscriberInterface
{
		use TaskPluginTrait;


	/**
	 * @var boolean
	 * @since 4.1.0
	 */
	protected $autoloadLanguage = true;
	/**
	 * @var string[]
	 *
	 * @since 4.1.0
	 */
	protected const TASKS_MAP = [
		'cinema' => [
			'langConstPrefix' => 'PLG_TASK_CINEMA',
			'form'            => 'cinema',
			'method'          => 'cinema',
		],
	];
	protected $myparams;

	/**
	 * @inheritDoc
	 *
	 * @return string[]
	 *
	 * @since 4.1.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	protected function cinema(ExecuteTaskEvent $event): int {
		$app = Factory::getApplication();
		$this->myparams = $event->getArgument('params');
		
		$addr = "https://culture-relax.org/theater/cinema-le-melies";
		$user_agent = 'Curl/1.0';
/*
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $addr);
		curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_NOBODY, 0);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

		curl_setopt($ch,CURLOPT_FOLLOWLOCATION,TRUE);
		curl_setopt($ch,CURLOPT_MAXREDIRS,10000); # Fix by Nicholas
		
		$res = curl_exec($ch);
		echo 'Erreur Curl : ' . curl_error($ch);
		curl_close($ch);
		*/
		
		$fp = @fopen($addr, "r") ;
		
		$data = "" ;
		if($fp) {
		    while (!feof($fp)) {
		        $data .= fread($fp, 1024) ;
		    }
		}
		fclose($fp) ;
		    
//		$res = str_replace("\n", "", $res);
		$deb = strpos($data,'<script id="__NEXT_DATA__" type="application/json">');
		$json = substr($data,$deb+51);
		$end = strpos($json,'</script>');
		$json = substr($json,0,$end);
		$decode  = json_decode($json);
		$events = $decode->props->pageProps->eventSessions->data;
		
		foreach($events as $one) {
		    if ($one->attributes->eventSessionType != "Movie") continue;
		    $datetime = date('Y-m-d H:i:00',strtotime($one->attributes->date.' '.$one->attributes->time));
		    if ($datetime < date('Y-m-d H:i:00')) continue; // ignore passed dates
		    $create = true;
		    if ($this->checkJEvent('handicaps-ensemble',$datetime)) {
		        $create = false; // la date existe déjà dans JEvents
		    }
		    // create a new event
		    $this->createJEvent($one->attributes,$create);
		}
		return TaskStatus::OK;		
	}
	private function createJEvent($one,$create) {
	    $mois = ['Jan' => 'janvier' ,'Feb' => 'février','Mar' => 'mars',
	        'Apr' => 'avril','May' => 'mai', 'Jun' => 'juin', 'Jul' => "juillet",
	        'Aug' => 'août','Sept' => 'septembre', 'Oct' => 'octobre',
	        'Nov' => 'novembre', 'Dec' => 'décembre'
	    ];
	    // date de l'événement
	    $ladate = date('d M Y à H:i',strtotime($one->date.' '.$one->time));
	    $ladate =strtr($ladate,$mois);
	    $description = '<p><b>Séance le '.$ladate.'</b>.</p>';
	    // $description .= $one->location;
	    if ($one->movie->data) {
            $movie = $one->movie->data->attributes;
            $description .= '<p class="cinema_title">'.$movie->title.'. </p>';
            $description .= '<p class="cinema_duration">'.$movie->genres.', durée : '.$movie->duration.'. </p>';
            if ($movie->featured_image_url) {
                $description .= "<p class='cinema_img_p'><img src='".$movie->featured_image_url."' class='cinema_img' style='float:left; max-width: 30%; height: auto; margin-right: 1em;'/></p>";
            }
            $description .= '<p class="cinema_descr">.'.$movie->description."</p>";
	    } else { // programmation en cours
	        $description .= "<p class='cinema_img_p'><img src='https://culture-relax.org/img/tba.png' class='cinema_img' style='float:left; max-width: 10%; height: auto; margin-right: 1em;'/></p>";
            $description .= '<p class="cinema_descr">'.$one->additionalInformation.'. </p>';
            $description .= '<p class="cinema_desc">La programmation n’est pas encore choisie.<br>Elle sera mise en ligne au fur et à mesure, et les abonnés à la Lettre d\'Information la recevront automatiquement.</p>';
	    }
		$description .= '<p style="clear:both">&nbsp;</p><p><a href="https://culture-relax.org/organizer/1085-montreuil" target="_blank" rel="noopener">Accéder à la page Montreuil du site Ciné Relax</a></p>';
	    // store this in jevents
		$db = Factory::getDbo();
		if ($create) { // new event
			$obj = new \stdClass();
			$obj->dtstart = strtotime($one->date.' '.$one->time);
			$obj->dtend = strtotime(date('Y-m-d 23:59:59',strtotime($one->date)));
			$obj->description = $description;
			$obj->location = "Cinéma le Méliès, Montreuil";
			$obj->summary = "Ciné Relax";
			$obj->noendtime = 1;
			$obj->multiday = 1;
			$obj->state = 1;
			$obj->rawdata = "";
			$obj->url = "";
			$obj->extra_info =  "";
			$result = $db->insertObject('#__jevents_vevdetail', $obj);
			$new_detail_id = $db->insertid();
			$obj = new \stdClass();
			$obj->icsid = 1;
			$obj->catid = 12;
			$obj->uid = md5(uniqid(rand(), true));
			$obj->created_by = 276;
			$obj->rawdata = "";
			$obj->detail_id = $new_detail_id;
			$obj->state = 1;  // publié
			$obj->access = 1; // public
			$result = $db->insertObject('#__jevents_vevent', $obj);
			$new_event_id = $db->insertid();
			$obj = new \stdClass();
			$obj->eventid = $new_event_id;
			$obj->eventdetail_id = $new_detail_id;
			$obj->startrepeat = $one->date.' '.$one->time;
			$obj->endrepeat = date('Y-m-d 23:59:59',strtotime($one->date));
			$obj->duplicatecheck = md5($new_event_id . strtotime($one->date.' '.$one->time));
			$result = $db->insertObject('#__jevents_repetition', $obj);
		} else { // update event
			$updateNulls = true;
			$query = $db->getQuery(true);
			$fields = array(
				$db->quoteName('description') . ' = ' . $db->quote($description)
				);
			$conditions = array(
				$db->quoteName('dtstart') . ' = '.strtotime($one->date.' '.$one->time)
				);				
				$query->update($db->quoteName('#__jevents_vevdetail'))->set($fields)->where($conditions);		
			$db->setQuery($query);
			$result = $db->execute();				
		}
	}
    
    private function checkJEvent($type,$date) {
		$db = Factory::getDbo();
		$query = $db->getQuery(true);	
		$query->select("detail.description")
		->from("#__jevents_vevdetail detail ")
		->innerJoin("#__jevents_repetition vrepet ON detail.evdet_id = vrepet.eventdetail_id ")
		->innerJoin("#__jevents_vevent vevent ON vevent.ev_id = vrepet.eventid ")
		->innerJoin("#__categories cat ON vevent.catid = cat.id")
		->where("cat.extension = 'com_jevents' AND cat.alias = '".$type."' AND vevent.state > 0  AND vrepet.startrepeat = '".$date."'");
		$db->setQuery($query);
		$res = $db->loadObjectList();
        if (!count($res)) { // pas d'evenement: on sort
		    return false;
        }
		return true;
	}

	function cleantext($text)
	{
		$text = str_replace('<p>', ' ', $text);
		$text = str_replace('</p>', ' ', $text);
		$text = strip_tags($text, '<br>');
		$text = trim($text);
		return $text;
	}	
}