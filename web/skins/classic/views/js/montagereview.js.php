
var server_utc_offset = <?php
$TimeZone = new DateTimeZone( ini_get('date.timezone') );
$now = new DateTime('now', $TimeZone);
$offset = $TimeZone->getOffset($now);
echo $offset . '; // ' . floor($offset / 3600) . ' hours ';
?>

var currentScale=<?php echo $defaultScale?>;
var liveMode=<?php echo $liveMode?>;
var fitMode=<?php echo $fitMode?>;

// slider scale, which is only for replay and relative to real time
var currentSpeed=<?php echo $speeds[$speedIndex]?>;  
var speedIndex=<?php echo $speedIndex?>;

// will be set based on performance, this is the display interval in milliseconds 
// for history, and fps for live, and dynamically determined (in ms)

var currentDisplayInterval=<?php echo $initialDisplayInterval?>;
var playSecsperInterval=1;         // How many seconds of recorded image we play per refresh determined by speed (replay rate) and display interval; (default=1 if coming from live)
var timerInterval;               // milliseconds between interrupts
var timerObj;                    // object to hold timer interval;
var freeTimeLastIntervals=[];    // Percentage of current interval used in loading most recent image
var imageLoadTimesEvaluated=0;   // running count
var imageLoadTimesNeeded=15;     // and how many we need
var timeLabelsFractOfRow = 0.9;

<?php

// Because we might not have time as the criteria, figure out the min/max time when we run the query


// This builds the list of events that are eligible from this range

$index = 0;
$anyAlarms = false;
$maxScore=0;

if ( !$liveMode ) {
  $result = dbQuery($eventsSql);
  if ( !$result ) {
    Fatal('SQL-ERR');
    return;
  }

  $EventsById = array();

  while( $event = $result->fetch(PDO::FETCH_ASSOC) ) {
    $event_id = $event['Id'];
    $EventsById[$event_id] = $event;
  }

  $next_frames = array();

  if ( $result = dbQuery($frameSql) ) {
    $next_frame = null;
    while( $frame = $result->fetch(PDO::FETCH_ASSOC) ) {
      $event_id = $frame['EventId'];
      $event = &$EventsById[$event_id];

      $frame['TimeStampSecs'] = $event['StartTimeSecs'] + $frame['Delta'];
      if ( !isset($event['FramesById']) ) {
        $event['FramesById'] = array();
        $frame['NextTimeStampSecs'] = 0;
      } else {
        $frame['NextTimeStampSecs'] = $next_frames[$frame['EventId']]['TimeStampSecs'];
        $frame['NextFrameId'] = $next_frames[$frame['EventId']]['FrameId'];
      }
      $event['FramesById'] += array( $frame['Id']=>$frame );
      $next_frames[$frame['EventId']] = $frame;
    }
  }

  echo "var events = {\n";
  foreach ( $EventsById as $event_id=>$event ) {

    $StartTimeSecs = $event['StartTimeSecs'];
    $EndTimeSecs = $event['EndTimeSecs'];

    # It isn't neccessary to do this for each event. We should be able to just look at the first and last
    if ( !$minTimeSecs or $minTimeSecs > $StartTimeSecs ) $minTimeSecs = $StartTimeSecs;
    if ( !$maxTimeSecs or $maxTimeSecs < $EndTimeSecs ) $maxTimeSecs = $EndTimeSecs;

    $event_json = json_encode($event, JSON_PRETTY_PRINT);
    echo " $event_id : $event_json,\n";

    $index = $index + 1;
    if ( $event['MaxScore'] > 0 ) {
      if ( $event['MaxScore'] > $maxScore )
        $maxScore = $event['MaxScore'];
      $anyAlarms = true;
    }
  }
echo " };\n";

  // if there is no data set the min/max to the passed in values
  if ( $index == 0 ) {
    if ( isset($minTime) && isset($maxTime) ) {
      $minTimeSecs = strtotime($minTime);
      $maxTimeSecs = strtotime($maxTime);
    } else {
      // this is the case of no passed in times AND no data -- just set something arbitrary
      $minTimeSecs = strtotime('1950-06-01 01:01:01');  // random time so there's something to display
      $maxTimeSecs = time() + 86400;
    }
  }

  // We only reset the calling time if there was no calling time
  if ( !isset($minTime) || !isset($maxTime) ) {
    $maxTime = strftime($maxTimeSecs);
    $minTime = strftime($minTimeSecs);
  } else {
    $minTimeSecs = strtotime($minTime);
    $maxTimeSecs = strtotime($maxTime);
  }

  echo "var maxScore=$maxScore;\n";  // used to skip frame load if we find no alarms.
} // end if initialmodeislive

echo "\nvar Storage = [];\n";
foreach ( Storage::find() as $Storage ) {
  echo 'Storage[' . $Storage->Id() . '] = ' . json_encode($Storage). ";\n";
}
echo "\nvar Servers = [];\n";
foreach ( Server::find() as $Server ) {
  echo 'Servers[' . $Server->Id() . '] = new Server(' . json_encode($Server). ");\n";
}
echo '
var monitorName = [];
var monitorLoading = [];
var monitorImageObject = [];
var monitorImageURL = [];
var monitorLoadingStageURL = [];
var monitorLoadStartTimems = [];
var monitorLoadEndTimems = [];
var monitorColour = [];
var monitorWidth = [];
var monitorHeight = [];
var monitorIndex = [];
var monitorNormalizeScale = [];
var monitorZoomScale = [];
var monitorCanvasObj = [];
var monitorCanvasCtx = [];
var monitorPtr = []; // monitorName[monitorPtr[0]] is first monitor
';

$numMonitors=0;  // this array is indexed by the monitor ID for faster access later, so it may be sparse
$avgArea=floatval(0);  // Calculations the normalizing scale

foreach ( $monitors as $m ) {
  $avgArea = $avgArea + floatval($m->Width() * $m->Height());
  $numMonitors++;
}

if ( $numMonitors > 0 ) $avgArea = $avgArea / $numMonitors;

$numMonitors = 0;
foreach ( $monitors as $m ) {
  echo "  monitorLoading["         . $m->Id() . "]=false;\n";
  echo "  monitorImageURL["        . $m->Id() . "]='".$m->getStreamSrc( array('mode'=>'single','scale'=>$defaultScale*100), '&' )."';\n";
  echo "  monitorLoadingStageURL[" . $m->Id() . "] = '';\n";
  echo "  monitorColour["          . $m->Id() . "]=\"" . $m->WebColour() . "\";\n";
  echo "  monitorWidth["           . $m->Id() . "]=" . $m->Width() . ";\n";
  echo "  monitorHeight["          . $m->Id() . "]=" . $m->Height() . ";\n";
  echo "  monitorIndex["           . $m->Id() . "]=" . $numMonitors . ";\n";
  echo "  monitorName["            . $m->Id() . "]=\"" . $m->Name() . "\";\n";
  echo "  monitorLoadStartTimems[" . $m->Id() . "]=0;\n";
  echo "  monitorLoadEndTimems["   . $m->Id() . "]=0;\n";
  echo "  monitorNormalizeScale["  . $m->Id() . "]=" . sqrt($avgArea / ($m->Width() * $m->Height() )) . ";\n";
  $zoomScale=1.0;
  if(isset($_REQUEST[ 'z' . $m->Id() ]) )
      $zoomScale = floatval( validHtmlStr($_REQUEST[ 'z' . $m->Id() ]) );
  echo "  monitorZoomScale["       . $m->Id() . "]=" . $zoomScale . ";\n";
  echo "  monitorPtr["         . $numMonitors . "]=" . $m->Id() . ";\n";
  $numMonitors += 1;
}
echo "
var numMonitors = $numMonitors;
var minTimeSecs=parseInt($minTimeSecs);
var maxTimeSecs=parseInt($maxTimeSecs);
";
echo "var rangeTimeSecs="   . ( $maxTimeSecs - $minTimeSecs + 1) . ";\n";
if(isset($defaultCurrentTime))
  echo "var currentTimeSecs=parseInt(" . strtotime($defaultCurrentTime) . ");\n";
else
  echo "var currentTimeSecs=parseInt(" . ($minTimeSecs + $maxTimeSecs)/2 . ");\n";

echo 'var speeds=[';
for ($i=0; $i<count($speeds); $i++)
  echo (($i>0)?', ':'') . $speeds[$i];
echo "];\n";
?>

var scrubAsObject=$('scrub');
var cWidth;   // save canvas width
var cHeight;  // save canvas height
var canvas;   // global canvas definition so we don't have to keep looking it up
var ctx;
var underSlider;    // use this to hold what is hidden by the slider
var underSliderX;   // Where the above was taken from (left side, Y is zero)
