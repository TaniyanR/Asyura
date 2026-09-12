<?php
declare(strict_types=1);

// Execute the real selection/rendering code against isolated fixtures.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'Asyura\\')) require dirname(__DIR__).'/src/'.substr($class, 7).'.php';
});
require dirname(__DIR__).'/src/Helpers.php';

final class WidgetTestDb extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->sqliteCreateFunction('FIND_IN_SET', static fn($needle,$list)=>in_array($needle,explode(',',$list),true)?1:0, 2);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('CURDATE()-INTERVAL ? DAY', "date('now','-' || ? || ' days')", $query);
        $query = str_replace('CURDATE()', "date('now')", $query);
        return parent::prepare($query, $options);
    }
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "OK $message\n";
}
$db = new WidgetTestDb();
$config = ['app_url'=>'https://asyura.example', 'app_key'=>'fixture-key'];
$db->exec("CREATE TABLE settings(setting_key TEXT,setting_value TEXT);
CREATE TABLE sites(id INTEGER,name TEXT,url TEXT,is_excluded INTEGER);
INSERT INTO sites VALUES(1,'Site One','https://one.example',0),(2,'Site Two','https://two.example',0);
CREATE TABLE reciprocal_links(id INTEGER,site_id INTEGER,partner_name TEXT,partner_url TEXT,status TEXT,reciprocal_link_enabled INTEGER,reciprocal_rss_enabled INTEGER,allocation_type TEXT,is_excluded INTEGER,slots TEXT,description TEXT,category TEXT,rel_type TEXT,open_new_tab INTEGER);
CREATE TABLE rss_feeds(id INTEGER,site_id INTEGER,reciprocal_link_id INTEGER,name TEXT,active INTEGER);
CREATE TABLE rss_items(id INTEGER,feed_id INTEGER,title TEXT,url TEXT,image_url TEXT,image_is_usable INTEGER,published_at TEXT);
CREATE TABLE raw_events(site_id INTEGER,event_type TEXT,is_bot INTEGER,is_suspicious INTEGER,referrer_host TEXT,occurred_at TEXT);
CREATE TABLE daily_link_stats(site_id INTEGER,target_host TEXT,stat_date TEXT,outbound_clicks INTEGER,widget_clicks INTEGER);
CREATE TABLE referrer_stats(site_id INTEGER,referrer_host TEXT,stat_date TEXT,inbound INTEGER,unique_inbound INTEGER);
CREATE TABLE excluded_referrers(pattern TEXT,match_type TEXT,active INTEGER);
CREATE TABLE widgets(id INTEGER,site_id INTEGER,type TEXT,item_limit INTEGER,width TEXT,height TEXT,config_json TEXT,name TEXT,enabled INTEGER,template_html TEXT,custom_css TEXT);");
$partners = [
    11=>['normal',10,0,0,'approved',1,1],
    12=>['priority_200',3,1,0,'approved',1,1],
    13=>['normal',0,0,0,'approved',1,1],
    14=>['normal',10,0,1,'approved',1,1],
    15=>['excluded',10,0,0,'approved',1,1],
    16=>['normal',10,0,0,'pending',1,1],
    17=>['normal',10,0,0,'paused',1,1],
    18=>['normal',4,0,0,'approved',1,0],
    19=>['normal',10,0,0,'approved',2,1],
    20=>['normal',3,3,0,'approved',1,1],
    21=>['normal',10,0,0,'approved',1,1],
    22=>['special',0,0,0,'approved',1,1],
    23=>['rescue',0,0,0,'approved',1,1],
];
foreach ($partners as $id=>[$allocation,$in,$out,$excluded,$status,$site,$rss]) {
    $host="partner$id.example";
    $db->prepare('INSERT INTO reciprocal_links VALUES(?,?,?,?,?,1,?,?,?,?,?,?,?,1)')->execute([$id,$site,"Partner $id","https://$host/",$status,$rss,$allocation,$excluded,'A','','','follow']);
    $db->prepare('INSERT INTO rss_feeds VALUES(?,?,?,?,?)')->execute([$id,$site,$id,'Feed '.$id,$id===21?0:1]);
    $db->prepare('INSERT INTO rss_items VALUES(?,?,?,?,?,?,?)')->execute([$id,$id,'Article '.$id,"https://$host/article",'https://images.example/photo.jpg',1,date('Y-m-d H:i:s')]);
    for($i=0;$i<$in;$i++) $db->prepare('INSERT INTO raw_events VALUES(?,\'pageview\',0,0,?,?)')->execute([$site,$host,date('Y-m-d H:i:s')]);
    $db->prepare('INSERT INTO daily_link_stats VALUES(?,?,?,?,0)')->execute([$site,$host,date('Y-m-d'),$out]);
    $db->prepare('INSERT INTO referrer_stats VALUES(?,?,?,?,?)')->execute([$site,$host,date('Y-m-d'),$in,$in]);
}
// A second feed, a text-only article, and a mismatched cross-site feed.
$db->exec("INSERT INTO rss_feeds VALUES(112,1,12,'Second feed',1),(999,2,11,'Foreign',1);
INSERT INTO rss_items VALUES(112,112,'Second article','https://partner12.example/second','https://images.example/second.jpg',1,'2026-09-12'),(113,112,'Text article','https://partner12.example/text',NULL,0,'2026-09-12'),(999,999,'Foreign','https://foreign.example/','https://images.example/x.jpg',1,'2026-09-12');");
$service = new Asyura\DistributionService($db);
$distribution = array_column($service->latest(1), null, 'reciprocal_link_id');
check($distribution[11]['remaining_accesses']===10, 'normal conversion uses inbound minus outbound');
check($distribution[12]['remaining_accesses']===5, '200% conversion subtracts returned clicks');
check($distribution[13]['remaining_accesses']===0 && $distribution[20]['remaining_accesses']===0, 'zero inbound and fulfilled targets receive no allocation');
check($distribution[22]['remaining_accesses']>=1 && in_array($distribution[23]['remaining_accesses'],[1,2,3],true), 'special and rescue quotas remain available');
foreach([14,15,16,17,19,21] as $id) check(!isset($distribution[$id]), "ineligible partner $id omitted");
$renderer = new Asyura\WidgetRenderer($db, $config);
$widget = ['id'=>1,'site_id'=>1,'type'=>'rss','slot_code'=>'IMAGE-A','item_limit'=>100,'config_json'=>'{"feed_ids":[11]}'];
$images=$renderer->rss($widget);
$ids=array_column($images,'id');sort($ids);
check($ids===[11,12,18,22,23,112], 'old widget feed filter is ignored; all eligible registered feeds participate');
check(count($ids)===count(array_unique($ids)), 'no duplicate article in a widget');
$widget['slot_code']='TEXT-A';
check(in_array(113,array_column($renderer->rss($widget),'id'),true), 'text RSS accepts articles without images');
$links=$renderer->links(['site_id'=>1,'slot_code'=>'A']);
check(!in_array('Partner 14',array_column($links,'title'),true), 'excluded site is absent from reciprocal links');
check(in_array('Partner 15',array_column($links,'title'),true), 'RSS-only exclusion still allows a reciprocal link');
$ranking=$renderer->ranking(['site_id'=>1,'item_limit'=>100]);
check(!in_array('Partner 14',array_column($ranking,'title'),true), 'excluded site is absent from public ranking');
$legacy=['id'=>1,'type'=>'ranking','slot_code'=>'A','template_html'=>'<a href="{url}" rel="nofollow">{rank}. {title}</a> <span>{in_count}</span>','custom_css'=>'.asyura-ranking{font-size:13px;background:#fff;border:1px solid #ddd;padding:8px}'];
check(str_contains(Asyura\WidgetDesign::withDefaults($legacy)['template_html'],'ranking-row'), 'untouched legacy ranking receives starter design');
$custom=$legacy;$custom['custom_css']='.asyura-ranking{color:red}';
check(Asyura\WidgetDesign::withDefaults($custom)===$custom, 'custom HTML/CSS are preserved');
$custom['template_html']='┗ <a href="{url}">{title}</a><br>';
$rendered=$renderer->render($custom,[['title'=>'<script>alert(1)</script>','url'=>'https://example.com']]);
check(str_contains($rendered,'</a><br>') && str_contains($rendered,'&lt;script&gt;'), 'renderer preserves explicit line breaks and escapes titles');
check($renderer->render($legacy,[])==='', 'public output never substitutes sample entries');
$db->prepare('INSERT INTO widgets VALUES(1,1,\'rss\',10,\'80%\',\'500px\',?,\'RSS\',1,\'\',\'\')')->execute(['{"feed_ids":[11],"other_setting":"preserved"}']);
$_SESSION=['admin_site_id'=>1];
$_POST=['id'=>1,'name'=>'Updated','enabled'=>'1','item_limit'=>'12','template_html'=>'<a href="{url}">{title}</a><br>','custom_css'=>'.asyura-rss{width:100%;height:auto}'];
$save=new ReflectionMethod(Asyura\AdminController::class,'saveWidget');$save->invoke(new Asyura\AdminController($db,$config));
$saved=$db->query('SELECT * FROM widgets WHERE id=1')->fetch();$savedConfig=json_decode($saved['config_json'],true);
check(!isset($savedConfig['feed_ids']) && $savedConfig['other_setting']==='preserved','save removes obsolete feed filters and preserves other config');
check($saved['width']==='80%' && $saved['height']==='500px' && str_ends_with($saved['template_html'],'<br>'),'saving CSS editor preserves legacy dimensions and HTML breaks');
$_SESSION=['admin_site_id'=>2];
try {$save->invoke(new Asyura\AdminController($db,$config));throw new RuntimeException('Cross-site write allowed');}
catch(InvalidArgumentException $e){echo "OK another site cannot save this widget\n";}
echo "Widget behavior tests passed.\n";
