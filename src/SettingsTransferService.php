<?php
declare(strict_types=1);

namespace Asyura;

use InvalidArgumentException;
use PDO;
use Throwable;

final class SettingsTransferService
{
    public const FORMAT = 'asyura-settings-backup';
    public const VERSION = 1;

    private const SETTING_KEYS = [
        'ranking_period_days',
        'distribution_window_hours',
        'raw_retention_days',
        'distribution_retention_days',
        'aggregate_retention_days',
        'rss_item_retention_days',
    ];

    public function __construct(private PDO $db) {}

    public function export(): array
    {
        $settings = [];
        $settingStmt = $this->db->prepare('SELECT setting_key,setting_value FROM settings WHERE setting_key=?');
        foreach (self::SETTING_KEYS as $key) {
            $settingStmt->execute([$key]);
            $value = $settingStmt->fetchColumn();
            if ($value !== false) $settings[$key] = (string) $value;
        }

        $sites = [];
        foreach ($this->db->query('SELECT * FROM sites ORDER BY sort_order,id')->fetchAll() as $site) {
            $siteId = (int) $site['id'];
            $links = $this->rows('SELECT * FROM reciprocal_links WHERE site_id=? ORDER BY id', [$siteId]);
            $feeds = $this->rows('SELECT * FROM rss_feeds WHERE site_id=? ORDER BY id', [$siteId]);
            $feedsByLink = [];
            $looseFeeds = [];
            $feedUrlById = [];
            foreach ($feeds as $feed) {
                $feedUrlById[(int) $feed['id']] = (string) $feed['feed_url'];
                $clean = $this->pick($feed, ['name','feed_url','active']);
                $linkId = (int) ($feed['reciprocal_link_id'] ?? 0);
                if ($linkId > 0) $feedsByLink[$linkId][] = $clean;
                else $looseFeeds[] = $clean;
            }

            $exportLinks = [];
            foreach ($links as $link) {
                $exportLinks[] = [
                    'data' => $this->pick($link, [
                        'partner_name','partner_url','normalized_url','description','category','slots','status',
                        'rel_type','open_new_tab','is_priority','is_special','is_rescue','is_excluded',
                        'reciprocal_link_enabled','reciprocal_rss_enabled','rss_url','allocation_type',
                    ]),
                    'rss_feeds' => $feedsByLink[(int) $link['id']] ?? [],
                ];
            }

            $widgets = [];
            foreach ($this->rows('SELECT * FROM widgets WHERE site_id=? ORDER BY type,slot_code', [$siteId]) as $widget) {
                $data = $this->pick($widget, ['public_id','type','slot_code','name','enabled','item_limit','width','height','template_html','custom_css']);
                $widgetConfig = json_decode((string) ($widget['config_json'] ?? ''), true);
                $widgetConfig = is_array($widgetConfig) ? $widgetConfig : [];
                $feedUrls = [];
                foreach ((array) ($widgetConfig['feed_ids'] ?? []) as $feedId) {
                    if (isset($feedUrlById[(int) $feedId])) $feedUrls[] = $feedUrlById[(int) $feedId];
                }
                unset($widgetConfig['feed_ids']);
                $data['config'] = $widgetConfig;
                $data['feed_urls'] = array_values(array_unique($feedUrls));
                $widgets[] = $data;
            }

            $rotations = [];
            foreach ($this->rows('SELECT * FROM rotation_feeds WHERE site_id=? ORDER BY id', [$siteId]) as $rotation) {
                $feedIds = json_decode((string) ($rotation['feed_ids_json'] ?? ''), true);
                $feedUrls = [];
                foreach ((array) $feedIds as $feedId) {
                    if (isset($feedUrlById[(int) $feedId])) $feedUrls[] = $feedUrlById[(int) $feedId];
                }
                $data = $this->pick($rotation, ['slug','category','interval_minutes','image_required','active']);
                $data['feed_urls'] = array_values(array_unique($feedUrls));
                $rotations[] = $data;
            }

            $sites[] = [
                'site' => $this->pick($site, [
                    'sort_order','public_id','site_key','name','url','rss_url','search_console_property','login_url','github_url',
                    'normalized_url','category','description','admin_email','active','ranking_enabled','links_enabled','rss_enabled',
                    'rotation_enabled','is_priority','priority_multiplier','is_special','special_points','is_rescue',
                    'rescue_min_points','is_excluded','contact_ads_notice','contact_links_notice','contact_custom_enabled','contact_custom_text',
                ]),
                'aliases' => array_map(fn(array $row): array => $this->pick($row, ['alias_url','normalized_url','match_type','allow_tracking_origin']), $this->rows('SELECT * FROM site_aliases WHERE site_id=? ORDER BY id', [$siteId])),
                'reciprocal_links' => $exportLinks,
                'rss_feeds' => $looseFeeds,
                'widgets' => $widgets,
                'rotation_feeds' => $rotations,
                'conversion_rules' => array_map(fn(array $row): array => $this->pick($row, ['name','url_pattern','match_type','active']), $this->rows('SELECT * FROM conversion_rules WHERE site_id=? ORDER BY id', [$siteId])),
            ];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => date(DATE_ATOM),
            'settings' => $settings,
            'excluded_referrers' => array_map(fn(array $row): array => $this->pick($row, ['label','pattern','match_type','active']), $this->db->query('SELECT * FROM excluded_referrers ORDER BY id')->fetchAll()),
            'sites' => $sites,
        ];
    }

    /** @return array{sites:int,links:int,feeds:int,widgets:int} */
    public function import(array $backup): array
    {
        if (($backup['format'] ?? '') !== self::FORMAT || (int) ($backup['version'] ?? 0) !== self::VERSION) {
            throw new InvalidArgumentException('阿修羅の設定バックアップ（バージョン1）ではありません。');
        }
        $siteEntries = $backup['sites'] ?? null;
        if (!is_array($siteEntries) || count($siteEntries) > 1000) {
            throw new InvalidArgumentException('バックアップ内のサイト情報が正しくありません。');
        }

        $counts = ['sites'=>0,'links'=>0,'feeds'=>0,'widgets'=>0];
        $startedHere = !$this->db->inTransaction();
        if ($startedHere) $this->db->beginTransaction();
        try {
            $this->importSettings(is_array($backup['settings'] ?? null) ? $backup['settings'] : []);
            $this->importExcludedReferrers(is_array($backup['excluded_referrers'] ?? null) ? $backup['excluded_referrers'] : []);
            foreach ($siteEntries as $entry) {
                if (!is_array($entry) || !is_array($entry['site'] ?? null)) continue;
                $siteId = $this->upsertSite($entry['site']);
                $counts['sites']++;
                $this->importAliases($siteId, (array) ($entry['aliases'] ?? []));
                $feedIdsByUrl = [];
                foreach ((array) ($entry['reciprocal_links'] ?? []) as $linkEntry) {
                    if (!is_array($linkEntry)) continue;
                    $linkId = $this->upsertLink($siteId, (array) ($linkEntry['data'] ?? []));
                    $counts['links']++;
                    foreach ((array) ($linkEntry['rss_feeds'] ?? []) as $feed) {
                        $feedId = $this->upsertFeed($siteId, $linkId, (array) $feed);
                        if ($feedId > 0) {
                            $feedIdsByUrl[$this->urlKey((string) ($feed['feed_url'] ?? ''))] = $feedId;
                            $counts['feeds']++;
                        }
                    }
                }
                foreach ((array) ($entry['rss_feeds'] ?? []) as $feed) {
                    $feedId = $this->upsertFeed($siteId, null, (array) $feed);
                    if ($feedId > 0) {
                        $feedIdsByUrl[$this->urlKey((string) ($feed['feed_url'] ?? ''))] = $feedId;
                        $counts['feeds']++;
                    }
                }
                $this->importWidgets($siteId, (array) ($entry['widgets'] ?? []), $feedIdsByUrl, $counts);
                $this->importRotations($siteId, (array) ($entry['rotation_feeds'] ?? []), $feedIdsByUrl);
                $this->importConversionRules($siteId, (array) ($entry['conversion_rules'] ?? []));
            }
            if ($startedHere) $this->db->commit();
        } catch (Throwable $e) {
            if ($startedHere && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $counts;
    }

    private function upsertSite(array $site): int
    {
        $name = Security::cleanText((string) ($site['name'] ?? ''), 255);
        $url = Security::safeUrl((string) ($site['url'] ?? ''));
        if ($name === '' || $url === '') throw new InvalidArgumentException('サイト名またはサイトURLが正しくありません。');
        $normalized = UrlNormalizer::normalize($url);
        $publicId = preg_match('/^[a-f0-9]{16,32}$/i', (string) ($site['public_id'] ?? '')) ? (string) $site['public_id'] : Security::randomToken(8);
        $find = $this->db->prepare('SELECT id FROM sites WHERE public_id=? OR normalized_url=? ORDER BY public_id=? DESC LIMIT 1');
        $find->execute([$publicId, $normalized, $publicId]);
        $id = (int) ($find->fetchColumn() ?: 0);
        $values = $this->siteValues($site, $name, $url, $normalized);
        if ($id > 0) {
            $sql = 'UPDATE sites SET sort_order=?,name=?,url=?,rss_url=?,search_console_property=?,login_url=?,github_url=?,normalized_url=?,category=?,description=?,admin_email=?,active=?,ranking_enabled=?,links_enabled=?,rss_enabled=?,rotation_enabled=?,is_priority=?,priority_multiplier=?,is_special=?,special_points=?,is_rescue=?,rescue_min_points=?,is_excluded=?,contact_ads_notice=?,contact_links_notice=?,contact_custom_enabled=?,contact_custom_text=? WHERE id=?';
            $values[] = $id;
            $this->db->prepare($sql)->execute($values);
            return $id;
        }
        $unique = $this->db->prepare('SELECT COUNT(*) FROM sites WHERE public_id=?');
        $unique->execute([$publicId]);
        if ((int) $unique->fetchColumn() > 0) $publicId = Security::randomToken(8);
        $siteKey = preg_match('/^[a-f0-9]{32,64}$/i', (string) ($site['site_key'] ?? '')) ? (string) $site['site_key'] : Security::randomToken(24);
        $sql = 'INSERT INTO sites (public_id,site_key,sort_order,name,url,rss_url,search_console_property,login_url,github_url,normalized_url,category,description,admin_email,active,ranking_enabled,links_enabled,rss_enabled,rotation_enabled,is_priority,priority_multiplier,is_special,special_points,is_rescue,rescue_min_points,is_excluded,contact_ads_notice,contact_links_notice,contact_custom_enabled,contact_custom_text) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $this->db->prepare($sql)->execute(array_merge([$publicId,$siteKey], $values));
        return (int) $this->db->lastInsertId();
    }

    private function siteValues(array $site, string $name, string $url, string $normalized): array
    {
        return [
            max(0,(int)($site['sort_order']??2147483647)), $name, $url,
            $this->optionalUrl($site['rss_url']??null), $this->property($site['search_console_property']??null),
            $this->optionalUrl($site['login_url']??null), $this->optionalUrl($site['github_url']??null), $normalized,
            $this->text($site['category']??null,100), $this->text($site['description']??null,2000), $this->email($site['admin_email']??null),
            $this->flag($site,'active',1), $this->flag($site,'ranking_enabled',1), $this->flag($site,'links_enabled',1),
            $this->flag($site,'rss_enabled',1), $this->flag($site,'rotation_enabled',0), $this->flag($site,'is_priority',0),
            max(0,(float)($site['priority_multiplier']??1.5)), $this->flag($site,'is_special',0), max(0,(float)($site['special_points']??100)),
            $this->flag($site,'is_rescue',0), max(0,(float)($site['rescue_min_points']??1)), $this->flag($site,'is_excluded',0),
            $this->flag($site,'contact_ads_notice',0), $this->flag($site,'contact_links_notice',0), $this->flag($site,'contact_custom_enabled',0),
            $this->text($site['contact_custom_text']??null,3000),
        ];
    }

    private function upsertLink(int $siteId, array $link): int
    {
        $name = Security::cleanText((string) ($link['partner_name'] ?? ''), 255);
        $url = Security::safeUrl((string) ($link['partner_url'] ?? ''));
        if ($name === '' || $url === '') throw new InvalidArgumentException('相互リンク先の名前またはURLが正しくありません。');
        $normalized = UrlNormalizer::normalize($url);
        $find = $this->db->prepare('SELECT id FROM reciprocal_links WHERE site_id=? AND normalized_url=? LIMIT 1');
        $find->execute([$siteId,$normalized]);
        $id = (int) ($find->fetchColumn() ?: 0);
        $status = in_array($link['status']??'', ['pending','approved','paused','rejected','removed'], true) ? $link['status'] : 'pending';
        $rel = in_array($link['rel_type']??'', ['follow','nofollow','sponsored','ugc'], true) ? $link['rel_type'] : 'follow';
        $allocation = in_array($link['allocation_type']??'', ['normal','priority_120','priority_150','priority_200','special','rescue','excluded'], true) ? $link['allocation_type'] : 'normal';
        $values = [$name,$url,$normalized,$this->text($link['description']??null,2000),$this->text($link['category']??null,100),$this->slots($link['slots']??'A'),$status,$rel,$this->flag($link,'open_new_tab',1),$this->flag($link,'is_priority',0),$this->flag($link,'is_special',0),$this->flag($link,'is_rescue',0),$this->flag($link,'is_excluded',0),$this->flag($link,'reciprocal_link_enabled',1),$this->flag($link,'reciprocal_rss_enabled',0),$this->optionalUrl($link['rss_url']??null),$allocation];
        if ($id > 0) {
            $values[]=$id;$values[]=$siteId;
            $this->db->prepare('UPDATE reciprocal_links SET partner_name=?,partner_url=?,normalized_url=?,description=?,category=?,slots=?,status=?,rel_type=?,open_new_tab=?,is_priority=?,is_special=?,is_rescue=?,is_excluded=?,reciprocal_link_enabled=?,reciprocal_rss_enabled=?,rss_url=?,allocation_type=? WHERE id=? AND site_id=?')->execute($values);
            return $id;
        }
        $this->db->prepare('INSERT INTO reciprocal_links (site_id,partner_name,partner_url,normalized_url,description,category,slots,status,rel_type,open_new_tab,is_priority,is_special,is_rescue,is_excluded,reciprocal_link_enabled,reciprocal_rss_enabled,rss_url,allocation_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$siteId],$values));
        return (int) $this->db->lastInsertId();
    }

    private function upsertFeed(int $siteId, ?int $linkId, array $feed): int
    {
        $url = Security::safeUrl((string) ($feed['feed_url'] ?? ''));
        if ($url === '') return 0;
        $name = Security::cleanText((string) ($feed['name'] ?? ''), 255) ?: 'RSS';
        $find = $this->db->prepare('SELECT id FROM rss_feeds WHERE site_id=? AND feed_url=? AND ((reciprocal_link_id IS NULL AND ? IS NULL) OR reciprocal_link_id=?) LIMIT 1');
        $find->execute([$siteId,$url,$linkId,$linkId]);
        $id = (int) ($find->fetchColumn() ?: 0);
        if ($id > 0) $this->db->prepare('UPDATE rss_feeds SET name=?,active=? WHERE id=? AND site_id=?')->execute([$name,$this->flag($feed,'active',1),$id,$siteId]);
        else {
            $this->db->prepare('INSERT INTO rss_feeds (site_id,reciprocal_link_id,name,feed_url,active) VALUES (?,?,?,?,?)')->execute([$siteId,$linkId,$name,$url,$this->flag($feed,'active',1)]);
            $id = (int) $this->db->lastInsertId();
        }
        return $id;
    }

    private function importWidgets(int $siteId, array $widgets, array $feedIdsByUrl, array &$counts): void
    {
        foreach ($widgets as $widget) {
            if (!is_array($widget)) continue;
            $type = in_array($widget['type']??'', ['ranking','links','rss','notices'], true) ? $widget['type'] : '';
            $slot = preg_replace('/[^A-Z0-9_-]/i','', (string) ($widget['slot_code']??''));
            if ($type === '' || $slot === '') continue;
            $config = is_array($widget['config']??null) ? $widget['config'] : [];
            $feedIds=[];foreach((array)($widget['feed_urls']??[]) as $url){$key=$this->urlKey((string)$url);if(isset($feedIdsByUrl[$key]))$feedIds[]=$feedIdsByUrl[$key];}
            if($feedIds)$config['feed_ids']=array_values(array_unique($feedIds));
            $configJson=$config ? json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
            $find=$this->db->prepare('SELECT id FROM widgets WHERE site_id=? AND type=? AND slot_code=?');$find->execute([$siteId,$type,$slot]);$id=(int)($find->fetchColumn()?:0);
            $values=[Security::cleanText((string)($widget['name']??''),255)?:($type.' '.$slot),$this->flag($widget,'enabled',1),min(100,max(1,(int)($widget['item_limit']??10))),$this->dimension($widget['width']??'100%','100%'),$this->dimension($widget['height']??'auto','auto'),(string)($widget['template_html']??''),(string)($widget['custom_css']??''),$configJson];
            if($id>0){$values[]=$id;$values[]=$siteId;$this->db->prepare('UPDATE widgets SET name=?,enabled=?,item_limit=?,width=?,height=?,template_html=?,custom_css=?,config_json=? WHERE id=? AND site_id=?')->execute($values);}
            else{$publicId=preg_match('/^[a-f0-9]{16,32}$/i',(string)($widget['public_id']??''))?(string)$widget['public_id']:Security::randomToken(8);$check=$this->db->prepare('SELECT COUNT(*) FROM widgets WHERE public_id=?');$check->execute([$publicId]);if((int)$check->fetchColumn()>0)$publicId=Security::randomToken(8);$this->db->prepare('INSERT INTO widgets (site_id,public_id,type,slot_code,name,enabled,item_limit,width,height,template_html,custom_css,config_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$siteId,$publicId,$type,$slot],$values));}
            $counts['widgets']++;
        }
    }

    private function importAliases(int $siteId,array $aliases):void{foreach($aliases as $alias){if(!is_array($alias))continue;$url=Security::safeUrl((string)($alias['alias_url']??''));if($url==='')continue;$normalized=UrlNormalizer::normalize($url);$type=in_array($alias['match_type']??'',['host','prefix','contains','exact'],true)?$alias['match_type']:'host';$exists=$this->db->prepare('SELECT id FROM site_aliases WHERE site_id=? AND normalized_url=? AND match_type=?');$exists->execute([$siteId,$normalized,$type]);$id=(int)($exists->fetchColumn()?:0);if($id)$this->db->prepare('UPDATE site_aliases SET alias_url=?,allow_tracking_origin=? WHERE id=? AND site_id=?')->execute([$url,$this->flag($alias,'allow_tracking_origin',0),$id,$siteId]);else$this->db->prepare('INSERT INTO site_aliases (site_id,alias_url,normalized_url,match_type,allow_tracking_origin) VALUES (?,?,?,?,?)')->execute([$siteId,$url,$normalized,$type,$this->flag($alias,'allow_tracking_origin',0)]);}}

    private function importRotations(int $siteId,array $rotations,array $feedIdsByUrl):void{foreach($rotations as $row){if(!is_array($row))continue;$slug=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($row['slug']??''));if($slug==='')continue;$category=Security::cleanText((string)($row['category']??''),255);$ids=[];foreach((array)($row['feed_urls']??[]) as$url){$key=$this->urlKey((string)$url);if(isset($feedIdsByUrl[$key]))$ids[]=$feedIdsByUrl[$key];}$json=$ids?json_encode(array_values(array_unique($ids))):null;$stmt=$this->db->prepare('INSERT INTO rotation_feeds (site_id,slug,category,interval_minutes,image_required,feed_ids_json,active) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE interval_minutes=VALUES(interval_minutes),image_required=VALUES(image_required),feed_ids_json=VALUES(feed_ids_json),active=VALUES(active)');$stmt->execute([$siteId,$slug,$category,min(10080,max(1,(int)($row['interval_minutes']??60))),$this->flag($row,'image_required',0),$json,$this->flag($row,'active',1)]);}}

    private function importConversionRules(int $siteId,array $rules):void{foreach($rules as$row){if(!is_array($row))continue;$name=Security::cleanText((string)($row['name']??''),255);$pattern=mb_substr(trim((string)($row['url_pattern']??'')),0,2048);if($name===''||$pattern==='')continue;$type=in_array($row['match_type']??'',['exact','prefix','contains'],true)?$row['match_type']:'contains';$find=$this->db->prepare('SELECT id FROM conversion_rules WHERE site_id=? AND name=? AND url_pattern=? AND match_type=? LIMIT 1');$find->execute([$siteId,$name,$pattern,$type]);$id=(int)($find->fetchColumn()?:0);if($id)$this->db->prepare('UPDATE conversion_rules SET active=? WHERE id=? AND site_id=?')->execute([$this->flag($row,'active',1),$id,$siteId]);else$this->db->prepare('INSERT INTO conversion_rules (site_id,name,url_pattern,match_type,active) VALUES (?,?,?,?,?)')->execute([$siteId,$name,$pattern,$type,$this->flag($row,'active',1)]);}}

    private function importSettings(array $settings):void{$stmt=$this->db->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach(self::SETTING_KEYS as$key){if(array_key_exists($key,$settings))$stmt->execute([$key,(string)max(1,(int)$settings[$key])]);}}
    private function importExcludedReferrers(array $rows):void{$stmt=$this->db->prepare('INSERT INTO excluded_referrers (label,pattern,match_type,active) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),active=VALUES(active)');foreach($rows as$row){if(!is_array($row))continue;$label=Security::cleanText((string)($row['label']??''),255);$pattern=Security::cleanText((string)($row['pattern']??''),500);$type=in_array($row['match_type']??'',['host','contains','exact'],true)?$row['match_type']:'host';if($label!==''&&$pattern!=='')$stmt->execute([$label,$pattern,$type,$this->flag($row,'active',1)]);}}
    private function rows(string $sql,array $params):array{$stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();}
    private function pick(array $row,array $keys):array{$result=[];foreach($keys as$key)if(array_key_exists($key,$row))$result[$key]=$row[$key];return$result;}
    private function flag(array $row,string $key,int $default):int{return array_key_exists($key,$row)?(!empty($row[$key])?1:0):$default;}
    private function text(mixed $value,int $max):?string{$value=Security::cleanText((string)$value,$max);return$value!==''?$value:null;}
    private function email(mixed $value):?string{$value=trim((string)$value);return$value!==''&&filter_var($value,FILTER_VALIDATE_EMAIL)?$value:null;}
    private function optionalUrl(mixed $value):?string{$value=Security::safeUrl((string)$value);return$value!==''?$value:null;}
    private function property(mixed $value):?string{$value=trim((string)$value);return str_starts_with($value,'sc-domain:')||Security::safeUrl($value)!==''?$value:null;}
    private function slots(mixed $value):string{$slots=array_values(array_intersect(range('A','J'),explode(',',strtoupper((string)$value))));return$slots?implode(',',$slots):'A';}
    private function dimension(mixed $value,string $default):string{$value=trim((string)$value);return preg_match('/^(auto|\d+(?:\.\d+)?(?:px|%|rem|em|vh|vw)?)$/',$value)?$value:$default;}
    private function urlKey(string $url):string{return strtolower(rtrim($url,'/'));}
}
