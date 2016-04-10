<?php

chdir(__DIR__);

$startTime = time();

require_once('../incl/incl.php');
require_once('../incl/heartbeat.incl.php');
require_once('../incl/memcache.incl.php');
require_once('../incl/subscription.incl.php');

RunMeNTimes(1);
CatchKill();

ini_set('memory_limit', '128M');

if (!DBConnect()) {
    DebugMessage('Cannot connect to db!', E_USER_ERROR);
}

if (APIMaintenance()) {
    DebugMessage('API Maintenance in progress, not reporting watches!', E_USER_NOTICE);
    exit;
}

$stmt = $db->prepare('SELECT house, group_concat(concat_ws(\' \', region, name) order by 1 separator \', \') names, min(region) region, min(slug) slug from tblRealm group by house');
$stmt->execute();
$result = $stmt->get_result();
$houseNameCache = DBMapArray($result);
$stmt->close();

$loopStart = time();
$toSleep = 0;
while ((!$caughtKill) && (time() < ($loopStart + 60 * 30))) {
    heartbeat();
    sleep(min($toSleep, 20));
    if ($caughtKill || APIMaintenance()) {
        break;
    }
    ob_start();
    $toSleep = CheckNextUser();
    ob_end_flush();
    if ($toSleep === false) {
        break;
    }
}
DebugMessage('Done! Started ' . TimeDiff($startTime));

function CheckNextUser()
{
    $db = DBConnect();

    $sql = <<<'EOF'
select * 
from tblUser 
where watchesobserved > ifnull(watchesreported, '2000-01-01') 
and timestampadd(minute, greatest(if(paiduntil is null or paiduntil < now(), ?, ?), watchperiod), ifnull(watchesreported, '2000-01-01')) < now()
order by ifnull(watchesreported, '2000-01-01'), watchesobserved
limit 1
for update
EOF;

    $db->begin_transaction();

    $stmt = $db->prepare($sql);
    $freeFreq = SUBSCRIPTION_WATCH_MIN_PERIOD_FREE;
    $paidFreq = SUBSCRIPTION_WATCH_MIN_PERIOD;
    $stmt->bind_param('ii', $freeFreq, $paidFreq);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $result->close();
    $stmt->close();

    if (is_null($row)) {
        $db->rollback();
        return 10;
    }

    $stmt = $db->prepare('update tblUser set watchesreported = ? where id = ?');
    $userId = $row['id'];
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('si', $now, $userId);
    $stmt->execute();
    $stmt->close();

    $db->commit();

    $overdue = is_null($row['watchesreported']) ? strtotime($row['watchesobserved']) : (strtotime($row['watchesreported']) + $row['watchperiod'] * 60);
    DebugMessage("User " . str_pad($row['id'], 7, ' ', STR_PAD_LEFT) . " (" . $row['name'] . ') checking for new watches, overdue by ' . TimeDiff(
            $overdue, array(
                'parts'     => 2,
                'precision' => 'second'
            )
        )
    );

    ReportUserWatches($now, $row);

    return 0;
}

function ReportUserWatches($now, $userRow)
{
    global $LANG_LEVEL, $houseNameCache;

    $locale = $userRow['locale'];
    $LANG = GetLang($locale);

    $message = '';

    $db = DBConnect();
    
    $itemClassOrder = [2, 9, 6, 4, 7, 3, 14, 1, 15, 8, 16, 10, 12, 13, 17, 18, 5, 11];
    $itemClassOrderSql = '';
    foreach ($itemClassOrder as $idx => $classId) {
        $itemClassOrderSql .= "when $classId then $idx ";
    }
    
    $sql = <<<'EOF'
select 0 ispet, uw.seq, uw.region, uw.house,
    uw.item, uw.bonusset, ifnull(GROUP_CONCAT(bs.`bonus` ORDER BY 1 SEPARATOR '.'), '') bonusurl,
    i.name_%1$s name,
    ifnull(group_concat(ib.`tag_%1$s` order by ib.tagpriority separator ' '), if(ifnull(bs.`set`,0)=0,'',concat('%2$s ', i.level+sum(ifnull(ib.level,0))))) bonustag,
    case i.class %3$s else 999 end classorder,
    uw.direction, uw.quantity, uw.price, uw.currently
from tblUserWatch uw
join tblDBCItem i on uw.item = i.id
left join tblBonusSet bs on uw.bonusset = bs.`set`
left join tblDBCItemBonus ib on ifnull(bs.bonus, i.basebonus) = ib.id
where uw.user = ?
and uw.deleted is null
and uw.observed > ifnull(uw.reported, '2000-01-01')
group by uw.seq
union
select 1 ispet, uw.seq, uw.region, uw.house,
    uw.species, uw.breed, ifnull(uw.breed, '') breedurl,
    p.name_%1$s name, 
    null bonustag, 
    p.type classorder, 
    uw.direction, uw.quantity, uw.price, uw.currently
from tblUserWatch uw
JOIN tblDBCPet p on uw.species=p.id
where uw.user = ?
and uw.deleted is null
and uw.observed > ifnull(uw.reported, '2000-01-01')
order by if(region is null, 0, 1), house, region, ispet, classorder, name, bonustag, seq 
EOF;

    $sql = sprintf($sql, $locale, $LANG_LEVEL['__LEVEL_'.$locale.'__'], $itemClassOrderSql);

    $prevHouse = false;
    $updateSeq = [];
    $lastItem = '';

    $userId = $userRow['id'];
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $updateSeq[] = $row['seq'];
        if ($prevHouse !== $row['house']) {
            $prevHouse = $row['house'];
            $message .= '<br><b>' . $houseNameCache[$prevHouse]['names'] . '</b><br><br>';
        }

        $url = sprintf('https://theunderminejournal.com/#%s/%s/%s/%s%s',
            strtolower($houseNameCache[$prevHouse]['region']),
            $houseNameCache[$prevHouse]['slug'],
            $row['ispet'] ? 'battlepet' : 'item',
            $row['item'],
            $row['bonusurl'] ? ('.' . $row['bonusurl']) : ''
        );

        $bonusTag = $row['bonustag'];
        if ($row['ispet']) {
            if ($row['bonusset'] && isset($LANG['breedsLookup'][$row['bonusset']])) {
                $bonusTag = $LANG['breedsLookup'][$row['bonusset']];
            }
        }
        if ($bonusTag) {
            $bonusTag = ' ' . $bonusTag;
        }

        $lastItem = sprintf('[%s]%s', $row['name'], $bonusTag);
        $message .= sprintf('<a href="%s">[%s]%s</a>', $url, $row['name'], $bonusTag);

        $direction = $LANG[strtolower($row['direction'])];

        if (!is_null($row['price'])) {
            $value = FormatPrice($row['price'], $LANG);
            $currently = FormatPrice($row['currently'], $LANG);
            if (!is_null($row['quantity'])) {
                $condition = $LANG['priceToBuy'] . ' ' . $row['quantity'];
            } else {
                $condition = $LANG['marketPrice'];
            }
        } else {
            $value = $row['quantity'];
            $currently = $row['currently'];
            $condition = $LANG['availableQuantity'];
        }

        $message .= sprintf(' %s %s %s: %s <b>%s</b><br>', $condition, $direction, $value, $LANG['now'], $currently);
    }
    $result->close();
    $stmt->close();

    if (!count($updateSeq)) {
        return;
    }

    $message = $userRow['name'].',<br>' . $message . '<br><hr>' . $LANG['notificationsMessage'] . '<br><br>';

    if (is_null($userRow['paiduntil']) || (strtotime($userRow['paiduntil']) < time())) {
        $message .= $LANG['freeSubscriptionAccount'];
        $hoursNext = round((max(intval($userRow['watchperiod'],10), SUBSCRIPTION_WATCH_MIN_PERIOD_FREE)+5)/60, 1);
    } else {
        $message .= sprintf(preg_replace('/\{(\d+)\}/', '%$1$s', $LANG['paidExpires']), date('Y-m-d H:i:s e', strtotime($userRow['paiduntil'])));
        $hoursNext = round((max(intval($userRow['watchperiod'],10), SUBSCRIPTION_WATCH_MIN_PERIOD)+5)/60, 1);
    }

    if ($hoursNext > 0) {
        $hoursNext = sprintf(preg_replace('/\{(\d+)\}/', '%$1$s', $LANG['timeFuture']), $hoursNext . ' ' . ($hoursNext == 1 ? $LANG['timeHour'] : $LANG['timeHours']));
        $message .= ' ' . sprintf(preg_replace('/\{(\d+)\}/', '%$1$s', $LANG['notificationPeriodNext']), $hoursNext);
    }

    if (count($updateSeq) == 1) {
        $subject = $lastItem;
    } else {
        $subject = '' . count($updateSeq) . ' ' . $LANG['marketNotifications'];
    }

    SendUserMessage($userId, 'marketnotification', $subject, $message);

    $sql = 'update tblUserWatch set reported = \'%s\' where user = %d and seq in (%s)';
    $chunks = array_chunk($updateSeq, 200);
    foreach ($chunks as $seqs) {
        DBQueryWithError($db, sprintf($sql, $now, $userId, implode(',', $seqs)));
    }
}

function FormatPrice($amt, &$LANG) {
    $amt = round($amt);
    if ($amt >= 100) {// 1s
        $g = number_format($amt / 10000, 2, $LANG['decimalPoint'], $LANG['thousandsSep']);
        $v = '' . $g . $LANG['suffixGold'];
    } else {
        $c = $amt;
        $v = '' . $c . $LANG['suffixCopper'];
    }
    return $v;
}

function DBQueryWithError(&$db, $sql)
{
    $queryOk = $db->query($sql);
    if (!$queryOk) {
        DebugMessage("SQL error: " . $db->errno . ' ' . $db->error . " - " . substr(preg_replace('/[\r\n]/', ' ', $sql), 0, 500), E_USER_WARNING);
    }

    return $queryOk;
}