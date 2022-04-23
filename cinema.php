<?php
/** Cinema Task
* Version			: 4.0.0
* Package			: Joomla 4.1
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
		
		$addr = "http://www.cinemadifference.com/Montreuil.html";
		$user_agent = 'Mozilla HotFox 1.0';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $addr);
		curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_NOBODY, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$res = curl_exec($ch);
		curl_close($ch);
		
		$res = str_replace("\n", "", $res);
		$dates = $this->getTextBetween($res,'dl',2);
		$mois = ['janvier' => 'january','février' => 'february','mars' => 'march',
				'avril' => 'april','mai' => 'may', 'juin' => 'june', 'juillet' => "july",
				'août' => 'august','septembre' => 'september', 'octobre' => 'october',
				'novembre' => 'november', 'décembre' => 'december'
		];
		foreach ($dates as $date) {
			$unedate = $this->getTextBetween($date,'dt',2)[0];
			if (strpos($unedate,'<strong>') === false) continue; // ce n'est pas une date
			$unedate = str_replace(array('<strong>','</strong>','Le ',' à' ),'',$unedate);
			$unedate = str_replace('&nbsp;',' ',$unedate);
			$unedate =str_replace(array('lundi ','mardi ','mercredi ','jeudi ','vendredi ','samedi ','dimanche ' ),'',$unedate);
			$unedate =strtr($unedate,$mois);
			$dateus = date('Y-m-d H:i:00',strtotime($unedate));
			$create = true;
			if ($this->checkJEvent('handicaps-ensemble',$dateus)) {
				$create = false; // la date existe déjà dans JEvents
			}
			// create a new event
			$this->createJEvent($date,$unedate,$dateus,$create);
		}
		return TaskStatus::OK;		
	}
	private function getTextBetween($string,$tag,$indice)
	{
	    $pattern = '#<'.$tag.'(.*?)>(.*?)</'.$tag.'>#i';
	    preg_match_all($pattern, $string, $matches);
	    return ($matches[$indice]);
	}
	private function createJEvent($text,$unedate,$dateus,$create) {
	    // date de l'événement
		$description = $this->getTextBetween($text,'dt',2)[0].'&nbsp;';
		// lieu
		$pattern = '#<dd class="crayon(.*?) lieu">(.*?)</dd>#i';
	    preg_match_all($pattern, $text, $matches);
		$description .= '<br/>'.$matches[2][0].'&nbsp;';
		// autres informations de l'événement
		$pattern = '#<dd class="clearfix">(.*?)</dd>#i';
	    preg_match_all($pattern, $text, $matches);
	    $description .= $matches[1][0];
		$description = str_replace('spip_documents_left','float-left" style="margin-right:1em"',$description);
		$description = str_replace('a href="','a target="_blank" href="https://www.cinemadifference.com/',$description);
		// add cinemadifference link
		$description .= '<p style="clear:both">&nbsp;</p><p><a href="http://www.cinemadifference.com/Montreuil.html" target="_blank" rel="noopener">Accéder à la page Montreuil du site Ciné Ma Différence</a></p>';
		// get images and copy them to images directory
		$pattern = '#src="(.*?)" height#i';
	    preg_match_all($pattern, $text, $matches);
	    $img = $matches[1][0];
	    $imgpos = strpos($img,'arton');
	    $imgname = substr($img,$imgpos,strpos($img,'?')- $imgpos);
	    $new = JPATH_ROOT.'/images/cinemadifference/'.$imgname;
	    if (!copy('http://www.cinemadifference.com/'.$img,$new) ) {
	        echo "La copie $img du fichier a échoué...\n";
	    }
		// update image 
	    $description = str_replace($img,'images/cinemadifference/'.$imgname,$description);
		// get pdf 
		$strpos = strpos($description,"nom_fichier=affiche");
		$nofichier = substr($description,$strpos+20,4);
		$addr = "http://www.cinemadifference.com/spip.php?page=spipdf&spipdf=affiche_cinediff&id_evenement=".$nofichier."&nom_fichier=affiche_".$nofichier;
		$user_agent = 'Mozilla HotFox 1.0';
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $addr);
		curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_NOBODY, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$res = curl_exec($ch);
		curl_close($ch);
		$file = fopen(JPATH_ROOT.'/images/cinemadifference/affiche_'.$nofichier.'.pdf','w');
		fwrite($file,$res);
		fclose($file);
		// update pdf link
		$addr = "https://www.cinemadifference.com/spip.php?page=spipdf&amp;spipdf=affiche_cinediff&amp;id_evenement=".$nofichier."&amp;nom_fichier=affiche_".$nofichier;
		$description = str_replace($addr,'images/cinemadifference/affiche_'.$nofichier.'.pdf',$description);
	    // store this in jevents
		$db = Factory::getDbo();
		if ($create) { // new event
			$obj = new \stdClass();
			$obj->dtstart = strtotime($unedate);
			$obj->dtend = strtotime(date('Y-m-d 23:59:59',strtotime($unedate)));
			$obj->description = $description;
			$obj->location = "Cinéma le Méliès, Montreuil";
			$obj->summary = "Ciné Ma Différence";
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
			$obj->startrepeat = $dateus;
			$obj->endrepeat = date('Y-m-d 23:59:59',strtotime($unedate));
			$obj->duplicatecheck = md5($new_event_id . strtotime($unedate));
			$result = $db->insertObject('#__jevents_repetition', $obj);
		} else { // update event
			$updateNulls = true;
			$query = $db->getQuery(true);
			$fields = array(
				$db->quoteName('description') . ' = ' . $db->quote($description)
				);
			$conditions = array(
				$db->quoteName('dtstart') . ' = '.strtotime($unedate)
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