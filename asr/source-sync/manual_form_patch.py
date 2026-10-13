"""Wrap exact archived manual action bodies; keep existing fields, permission checks and SQL semantics."""
import re

def body_between(s,start,end):
    if s.count(start)!=1 or s.count(end)!=1:raise ValueError('Manual action anchors changed')
    a=s.index(start);b=s.index(end,a);block=s[a:b];first=block.index('{')+1
    close=block.rfind('}')
    if close<first:raise ValueError('Manual action boundary changed')
    return a,b,block[:first],block[first:close]

def wrap(name,s):
    helper="\\MapaSource\\SourceManualTransactions"
    if name=='ambulance_running.php':
        a,b,outer,body=body_between(s,"// 1. Process Form Submissions","// 2. Fetch Context Data")
        replacement=outer+f'''
    try {{
        $mapaResult = {helper}::run($pdo, 'ambulance_running.php', $_POST, $_SESSION, static function() use ($pdo, $isDriver, $driver_ambulance, $isCoordinator, $coord_type_id, $can_manage, &$message, &$error) {{
{body}
            return ['message'=>$message, 'error'=>$error];
        }});
        $message = $mapaResult['message'] ?? ''; $error = $mapaResult['error'] ?? '';
    }} catch (\\MapaSource\\CommandRejected $e) {{ $error = $e->getMessage(); }}
      catch (Throwable $e) {{ $error = 'The source action could not be saved. Retry this form; no partial database change was applied.'; }}
}}

'''
        s=s[:a]+replacement+s[b:]
    elif name=='off_road_ambulances.php':
        a,b,outer,body=body_between(s,'// Handle Actions',"if (isset($_GET['msg']) && $_GET['msg'] === 'repaired')")
        for old,new in [("header(\"Location: off_road_ambulances.php?repair_id=\" . $pdo->lastInsertId());\n        exit;","return ['message'=>'', 'error'=>'', 'redirect'=>'off_road_ambulances.php?repair_id=' . $pdo->lastInsertId()];"),("header(\"Location: off_road_ambulances.php?msg=repaired\");\n        exit;","return ['message'=>'', 'error'=>'', 'redirect'=>'off_road_ambulances.php?msg=repaired'];")]:
            if body.count(old)!=1:raise ValueError('Repair redirect anchor changed')
            body=body.replace(old,new)
        upload_start="        if (!empty($_FILES['repair_photos']['name'][0])) {"
        # Both archive upload loops append files; retain surrounding insert/update and URLs.
        for end_marker,replacement in [("        $photos_json = !empty($uploaded_photos)","        $uploaded_photos = $mapaPhotos->store();\n"),("        if ($new_uploads > 0)","        $mapaNewPhotos = $mapaPhotos->store(); $new_uploads = count($mapaNewPhotos); $uploaded_photos = array_merge($uploaded_photos, $mapaNewPhotos);\n")]:
            begin=body.index(upload_start);end=body.index(end_marker,begin)
            body=body[:begin]+replacement+body[end:]
        replacement=outer+f'''
    try {{
    $mapaPhotos = new \\MapaSource\\SourceRepairPhotos($_FILES, __DIR__ . '/../uploads/ambulance_repairs');
    $mapaOriginalRepair = static function() use ($pdo, &$success, &$error, $mapaPhotos) {{
{body}
        return ['message'=>$success, 'error'=>$error];
    }};
        $mapaResult = {helper}::run($pdo, 'off_road_ambulances.php', $_POST, $_SESSION, $mapaOriginalRepair, $mapaPhotos);
        $success = $mapaResult['message'] ?? ''; $error = $mapaResult['error'] ?? '';
        if (!empty($mapaResult['redirect'])) {{ header('Location: '.$mapaResult['redirect']); exit; }}
    }} catch (\\MapaSource\\CommandRejected $e) {{ $error = $e->getMessage(); }}
      catch (Throwable $e) {{ $error = 'The source action could not be confirmed. Retry this same form before submitting a new one.'; }}
}}

'''
        s=s[:a]+replacement+s[b:]
    elif name=='ambulance_analytics.php':
        a,b,outer,body=body_between(s,"if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $isAdmin",'// 1. Fetch Filters')
        replacement=outer+f'''
    try {{
        $mapaResult = {helper}::run($pdo, 'ambulance_analytics.php', $_POST, $_SESSION, static function() use ($pdo, &$message, &$error_message) {{
{body}
            return ['message'=>$message ?? '', 'error'=>$error_message ?? ''];
        }});
        $message = $mapaResult['message'] ?? ''; $error_message = $mapaResult['error'] ?? '';
    }} catch (\\MapaSource\\CommandRejected $e) {{ $error_message = $e->getMessage(); }}
      catch (Throwable $e) {{ $error_message = 'The source action could not be saved. Retry this form; no partial database change was applied.'; }}
}}

'''
        s=s[:a]+replacement+s[b:]
    else:return s,0
    require="require_once __DIR__ . '/_mapa_bridge/original-writer-hook.php';"
    if s.count(require)!=1:raise ValueError('Missing original writer guard')
    s=s.replace(require,require+"\nrequire_once __DIR__ . '/_mapa_bridge/SourceManualTransactions.php';")
    expected=len(re.findall(r'<form[^\n]*method="POST"',s))
    s,count=re.subn(r'(^[ \t]*<form[^\n]*method="POST"[^\n]*>[ \t]*$)',lambda m:m.group(1)+f"\n<?php echo {helper}::fields($_SESSION); ?>",s,flags=re.M)
    if count!=expected or count==0:raise ValueError('Not every original POST form received retry/CSRF fields')
    return s,count
