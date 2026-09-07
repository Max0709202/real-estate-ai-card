<?php
/**
 * 物件詳細「マップ・周辺情報」機能のサーバー側ロジック
 * （依頼書「物件詳細『マップ・周辺情報』機能の実装依頼 2026.9.3」）。
 *
 * 役割
 *  - 物件住所 → 緯度・経度（一度取得したら properties.lat/lng に保存して再取得しない）
 *  - 地図に置くピンの最小情報（物件名・価格・間取り・面積）の組み立て
 *  - 周辺施設の検索（Google Places API (New)・サーバー側からのみ呼ぶ）
 *  - ハザード／学区／指定緊急避難場所（国土交通省「不動産情報ライブラリ」）の面・点データ
 *
 * 依頼書の必須仕様のうち、このファイルが担保するもの
 *  - §5  周辺施設は「施設名」「位置」「Googleマップで見る」だけを返す
 *        （徒歩時間・距離・公式サイトURL・その他詳細は取得も返却もしない）
 *  - §6  検索範囲は対象物件を中心に半径 PROPERTY_MAP_RADIUS_M（既定1000m）
 *  - §9  関連カテゴリーはグループ単位でまとめて取得する（A:交通・買物 / B:教育・医療 / C:飲食・注意施設）
 *  - §9  まとめ取得で不足したカテゴリーだけ追加検索する（表示品質はAPI回数より優先）
 *  - §12 Routes API は使用しない（距離・所要時間は一切計算しない）
 *  - §8⑩ 位置を正確に取得できない施設は推測で作らない（APIが返した施設だけを返す）
 *
 * APIキーはブラウザへ出さない。地図表示用の GOOGLE_MAPS_API_KEY だけが map.php 経由で
 * ブラウザへ渡り、Places 検索は必ずこのファイル（サーバー側）から実行する。
 */

require_once __DIR__ . '/chat-public-data-helper.php';

/* ──────────────────────────────────────────────────────────
 * 定数・カテゴリー定義
 * ────────────────────────────────────────────────────────── */

if (!function_exists('propertyMapRadius')) {
    /** 周辺施設の基本検索範囲（半径メートル・§6）。 */
    function propertyMapRadius(): int
    {
        $r = defined('PROPERTY_MAP_RADIUS_M') ? (int)PROPERTY_MAP_RADIUS_M : 1000;
        return max(200, min(3000, $r));
    }
}

if (!function_exists('propertyMapCategoryDefs')) {
    /**
     * 周辺情報の10カテゴリー（依頼書§4の並び順どおり）。
     *   source : places（Google Places API） / reinfolib（不動産情報ライブラリ）
     *   group  : Places のまとめ取得グループ（§9）。A=交通・買物 / B=教育・医療 / C=飲食・注意施設
     *   render : marker（ピン） / polygon（該当エリアの色分け）
     */
    function propertyMapCategoryDefs(): array
    {
        return [
            'hazard'          => ['label' => 'ハザード情報',     'source' => 'reinfolib', 'group' => null, 'render' => 'polygon'],
            'station'         => ['label' => '駅／バス停',       'source' => 'places',    'group' => 'A',  'render' => 'marker'],
            'store'           => ['label' => 'スーパー／コンビニ', 'source' => 'places',   'group' => 'A',  'render' => 'marker'],
            'hospital'        => ['label' => '病院',             'source' => 'places',    'group' => 'B',  'render' => 'marker'],
            'school'          => ['label' => '学校（全て）',      'source' => 'places',    'group' => 'B',  'render' => 'marker'],
            'school_district' => ['label' => '学校（学区）',      'source' => 'reinfolib', 'group' => null, 'render' => 'polygon'],
            'cram'            => ['label' => '学習塾',           'source' => 'places',    'group' => 'B',  'render' => 'marker'],
            'restaurant'      => ['label' => 'レストラン',        'source' => 'places',    'group' => 'C',  'render' => 'marker'],
            'shelter'         => ['label' => '緊急避難場所',      'source' => 'reinfolib', 'group' => null, 'render' => 'marker'],
            'caution'         => ['label' => '周辺の注意施設',    'source' => 'places',    'group' => 'C',  'render' => 'marker'],
        ];
    }
}

if (!function_exists('propertyMapPlaceTypes')) {
    /**
     * カテゴリー → Google Places API (New) の place type（Table A）。
     * 学習塾（cram）に相当する type は Places に存在しないため、ここには含めず
     * テキスト検索（propertyMapPlacesTextSearch）で取得する。
     */
    function propertyMapPlaceTypes(): array
    {
        return [
            'station'    => ['bus_station', 'bus_stop', 'train_station', 'subway_station', 'light_rail_station', 'transit_station', 'transit_depot'],
            'store'      => ['supermarket', 'convenience_store', 'grocery_store'],
            'hospital'   => ['hospital', 'doctor', 'dental_clinic'],
            'school'     => ['preschool', 'primary_school', 'school', 'secondary_school', 'university', 'child_care_agency'],
            'restaurant' => ['restaurant'],
            'caution'    => ['cemetery', 'funeral_home'],
        ];
    }
}

if (!function_exists('propertyMapCategoryLimit')) {
    /**
     * カテゴリーごとの最大表示件数。
     * レストランは件数が非常に多くなるため、近い施設を優先して最大10件（§8⑧）。
     */
    function propertyMapCategoryLimit(string $category): int
    {
        return $category === 'restaurant' ? 10 : 20;
    }
}

/** Places の1回の検索で取り寄せる最大件数（Places API (New) の上限は20）。 */
if (!defined('PROPERTY_MAP_PLACES_PAGE')) define('PROPERTY_MAP_PLACES_PAGE', 20);
/** Places / 不動産情報ライブラリのサーバー側キャッシュ保持時間（秒）。 */
if (!defined('PROPERTY_MAP_PLACES_TTL')) define('PROPERTY_MAP_PLACES_TTL', 2592000);   // 30日
if (!defined('PROPERTY_MAP_REINFO_TTL')) define('PROPERTY_MAP_REINFO_TTL', 2592000);   // 30日

/* ──────────────────────────────────────────────────────────
 * 緯度・経度（§1 / §2）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('propertyMapGeoOf')) {
    /**
     * 物件の緯度・経度を返す。properties.lat/lng に保存済みならそれを使い、
     * 未取得なら住所からジオコーディングして保存する（同じ物件で二度と引かない）。
     * 取得できない場合は null（依頼書§2「緯度・経度まで取得できている場合は」）。
     *
     * @param bool $allowFetch false のときは保存済みの座標だけを見る（未取得なら null）。
     */
    function propertyMapGeoOf(PDO $db, array $row, bool $allowFetch = true): ?array
    {
        $lat = isset($row['lat']) && $row['lat'] !== null && $row['lat'] !== '' ? (float)$row['lat'] : null;
        $lng = isset($row['lng']) && $row['lng'] !== null && $row['lng'] !== '' ? (float)$row['lng'] : null;
        if ($lat !== null && $lng !== null && ($lat !== 0.0 || $lng !== 0.0)) {
            return ['lat' => $lat, 'lng' => $lng];
        }
        if (!$allowFetch) return null;

        $address = trim((string)($row['address'] ?? ''));
        if ($address === '') return null;

        // 直近に試して失敗している場合は、しばらく再試行しない（画面表示のたびに叩かない）。
        $tried = $row['geo_fetched_at'] ?? null;
        if ($tried && strtotime((string)$tried) > time() - 86400) return null;

        $geo = propertyMapGeocodeAddress($db, $address);
        $propertyId = (int)($row['id'] ?? 0);
        if ($propertyId > 0) {
            try {
                $stmt = $db->prepare("UPDATE properties SET lat = ?, lng = ?, geo_fetched_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([
                    $geo ? $geo['lat'] : null,
                    $geo ? $geo['lng'] : null,
                    $propertyId,
                ]);
            } catch (Throwable $e) {
                error_log('propertyMapGeoOf save error: ' . $e->getMessage());
            }
        }
        return $geo;
    }
}

if (!function_exists('propertyMapGeocodeAddress')) {
    /**
     * 住所 → 緯度・経度。Google Geocoding API（番地まで解決できる）を優先し、
     * キー未設定・失敗時は既存のGSIジオコーダ（chatGeocodeAddressRobust）へフォールバックする。
     * どちらもサーバー側からのみ呼び出す（APIキーをブラウザへ出さない）。
     */
    function propertyMapGeocodeAddress(PDO $db, string $address): ?array
    {
        $address = trim($address);
        if ($address === '') return null;

        $key = propertyMapGeocodingKey();
        if ($key !== '') {
            $url = 'https://maps.googleapis.com/maps/api/geocode/json'
                . '?address=' . rawurlencode($address)
                . '&language=ja&region=jp'
                . '&key=' . rawurlencode($key);
            $res = chatPublicDataCachedGet($db, 'google_geocode', $url, [], PROPERTY_MAP_PLACES_TTL, 10);
            $data = $res['data'] ?? null;
            if (is_array($data) && ($data['status'] ?? '') === 'OK' && !empty($data['results'][0]['geometry']['location'])) {
                $loc = $data['results'][0]['geometry']['location'];
                if (isset($loc['lat'], $loc['lng'])) {
                    return ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
                }
            }
        }

        $geo = chatGeocodeAddressRobust($db, $address);
        if (is_array($geo) && isset($geo['lat'], $geo['lon'])) {
            return ['lat' => (float)$geo['lat'], 'lng' => (float)$geo['lon']];
        }
        return null;
    }
}

if (!function_exists('propertyMapGeocodingKey')) {
    /** ジオコーディングに使うキー。専用キー → Places キー → 地図キーの順で拾う。 */
    function propertyMapGeocodingKey(): string
    {
        foreach (['GOOGLE_GEOCODING_API_KEY', 'GOOGLE_PLACES_API_KEY', 'GOOGLE_MAPS_API_KEY'] as $name) {
            if (defined($name) && constant($name) !== '') return (string)constant($name);
        }
        return '';
    }
}

if (!function_exists('propertyMapPlacesKey')) {
    /** Places API (New) 用のキー（サーバー側専用）。 */
    function propertyMapPlacesKey(): string
    {
        if (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '') return (string)GOOGLE_PLACES_API_KEY;
        if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '') return (string)GOOGLE_MAPS_API_KEY;
        return '';
    }
}

/* ──────────────────────────────────────────────────────────
 * ピン情報（§2 / §3）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('propertyMapDisplayName')) {
    /** 地図のピンに出す物件名（JS の PropertyUI.displayName と同じ考え方）。 */
    function propertyMapDisplayName(array $row): string
    {
        $name = trim((string)($row['building_name'] ?? ''));
        if ($name !== '') return $name;
        $type = (string)($row['property_type'] ?? 'mansion');
        if ($type === 'house' || $type === 'land') {
            $a = trim((string)($row['address'] ?? ''));
            if ($a !== '') {
                $a = preg_replace('/^(北海道|東京都|京都府|大阪府|.{2,3}県)/u', '', $a);
                $a = preg_replace('/[0-9０-９一二三四五六七八九十]+\s*(丁目|番地|番|号).*$/u', '', $a);
                $a = preg_replace('/[0-9０-９][\-－0-9０-９\s].*$/u', '', $a);
                $a = trim((string)$a);
                if ($a !== '') return $a . ($type === 'land' ? '土地' : '戸建て');
            }
        }
        return '（名称未取得）';
    }
}

if (!function_exists('propertyMapAreaText')) {
    /** ピンに出す面積（マンションは専有面積、戸建・土地は土地／建物面積）。 */
    function propertyMapAreaText(array $row): string
    {
        $exclusive = trim((string)($row['exclusive_area'] ?? ''));
        if ($exclusive !== '') return $exclusive;
        $parts = [];
        $land = trim((string)($row['land_area'] ?? ''));
        $building = trim((string)($row['building_area'] ?? ''));
        if ($land !== '') $parts[] = '土地' . $land;
        if ($building !== '') $parts[] = '建物' . $building;
        return implode('／', $parts);
    }
}

if (!function_exists('propertyMapPin')) {
    /**
     * 地図のピン1件分。依頼書§2・§3 が求める最小項目だけを返す。
     *   物件名 / 価格 / 間取り / 面積（＋現在物件かどうか）
     * 現在見ている物件には「物件詳細を見る」リンクを付けない（§2）ので is_current で区別する。
     */
    function propertyMapPin(array $row, array $geo, bool $isCurrent): array
    {
        return [
            'id'         => (int)$row['id'],
            'name'       => propertyMapDisplayName($row),
            'price'      => trim((string)($row['price_text'] ?? '')),
            'layout'     => trim((string)($row['layout'] ?? '')),
            'area'       => propertyMapAreaText($row),
            'lat'        => (float)$geo['lat'],
            'lng'        => (float)$geo['lng'],
            'is_current' => $isCurrent ? 1 : 0,
        ];
    }
}

/* ──────────────────────────────────────────────────────────
 * Google Places API (New)（§5 / §6 / §7 / §9）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('propertyMapPlacesFieldMask')) {
    /**
     * 取得する項目は必要最低限（§13）。
     * 徒歩時間・距離・営業時間・評価・電話・公式サイトURLは一切要求しない（§5）。
     */
    function propertyMapPlacesFieldMask(string $prefix): string
    {
        $fields = ['id', 'displayName', 'location', 'types', 'primaryType', 'googleMapsUri'];
        return implode(',', array_map(static function ($f) use ($prefix) { return $prefix . '.' . $f; }, $fields));
    }
}

if (!function_exists('propertyMapNormalizePlace')) {
    /** Places のレスポンス1件 → 画面が必要とする最小形（施設名・位置・Googleマップリンク）。 */
    function propertyMapNormalizePlace(array $place): ?array
    {
        $name = trim((string)($place['displayName']['text'] ?? ''));
        $lat = $place['location']['latitude'] ?? null;
        $lng = $place['location']['longitude'] ?? null;
        // 施設名か位置が取れないものは推測で補わず捨てる（§8⑩・§15-18）。
        if ($name === '' || $lat === null || $lng === null) return null;
        $placeId = trim((string)($place['id'] ?? ''));
        return [
            'name'     => $name,
            'lat'      => (float)$lat,
            'lng'      => (float)$lng,
            'place_id' => $placeId,
            'map_url'  => propertyMapGoogleUrl($placeId, (float)$lat, (float)$lng),
            'types'    => array_values(array_filter(array_map('strval', (array)($place['types'] ?? [])))),
        ];
    }
}

if (!function_exists('propertyMapGoogleUrl')) {
    /**
     * 「Googleマップで見る」のリンク（§7）。
     * Place ID があればその施設のページを直接開き、無ければ座標を開く。
     * 徒歩・車・距離・所要時間はGoogleマップ側で確認する仕様のため、経路URLは作らない（§12）。
     */
    function propertyMapGoogleUrl(string $placeId, float $lat, float $lng): string
    {
        $query = rawurlencode($lat . ',' . $lng);
        $url = 'https://www.google.com/maps/search/?api=1&query=' . $query;
        if ($placeId !== '') $url .= '&query_place_id=' . rawurlencode($placeId);
        return $url;
    }
}

if (!function_exists('propertyMapPlacesNearby')) {
    /**
     * Places API (New) searchNearby。半径 $radius（既定1000m）の円内を近い順に検索する。
     * 返り値: ['items' => [正規化済み], 'ok' => bool, 'truncated' => bool]
     * truncated=true は「上限件数まで返ってきた＝円内にまだ他にある可能性がある」ことを示す。
     */
    function propertyMapPlacesNearby(PDO $db, float $lat, float $lng, array $types, int $max = PROPERTY_MAP_PLACES_PAGE): array
    {
        $key = propertyMapPlacesKey();
        $types = array_values(array_unique(array_filter($types)));
        if ($key === '' || empty($types)) return ['items' => [], 'ok' => false, 'truncated' => false];

        $max = max(1, min(PROPERTY_MAP_PLACES_PAGE, $max));
        $payload = [
            'includedTypes'       => $types,
            'maxResultCount'      => $max,
            'rankPreference'      => 'DISTANCE',   // 近い施設を優先（§8⑧）
            'languageCode'        => 'ja',
            'regionCode'          => 'JP',
            'locationRestriction' => [
                'circle' => [
                    'center' => ['latitude' => propertyMapRound($lat), 'longitude' => propertyMapRound($lng)],
                    'radius' => (float)propertyMapRadius(),
                ],
            ],
        ];
        $res = chatPublicDataCachedPostJson(
            $db,
            'google_places',
            'https://places.googleapis.com/v1/places:searchNearby',
            $payload,
            [
                'X-Goog-Api-Key'    => $key,
                'X-Goog-FieldMask'  => propertyMapPlacesFieldMask('places'),
            ],
            PROPERTY_MAP_PLACES_TTL
        );
        if (empty($res['ok']) || !is_array($res['data'])) {
            if (!empty($res['error']) || (int)($res['status'] ?? 0) >= 400) {
                error_log('[property-map] places searchNearby error: status=' . ($res['status'] ?? 'n/a') . ' error=' . ($res['error'] ?? ''));
            }
            return ['items' => [], 'ok' => false, 'truncated' => false];
        }
        $places = is_array($res['data']['places'] ?? null) ? $res['data']['places'] : [];
        $items = [];
        foreach ($places as $p) {
            if (!is_array($p)) continue;
            $n = propertyMapNormalizePlace($p);
            if ($n) $items[] = $n;
        }
        return ['items' => $items, 'ok' => true, 'truncated' => count($places) >= $max];
    }
}

if (!function_exists('propertyMapPlacesTextSearch')) {
    /**
     * Places API (New) searchText。type が用意されていない施設
     * （学習塾・清掃工場・下水処理場・刑務所・変電所 等）を実在の施設名で拾うために使う。
     * 円内に限定するので、返るのは実際にその範囲にあるとGoogleが持っている施設だけ（§8⑩ 推測表示の禁止）。
     */
    function propertyMapPlacesTextSearch(PDO $db, float $lat, float $lng, string $query, int $max = PROPERTY_MAP_PLACES_PAGE): array
    {
        $key = propertyMapPlacesKey();
        $query = trim($query);
        if ($key === '' || $query === '') return ['items' => [], 'ok' => false, 'truncated' => false];

        $max = max(1, min(PROPERTY_MAP_PLACES_PAGE, $max));
        // searchText の locationRestriction は矩形しか受け付けないため、半径から外接する矩形を作り、
        // 取得後に半径 propertyMapRadius() 以内へ絞り込む（§6 検索範囲）。
        $box = propertyMapBoundingBox($lat, $lng, propertyMapRadius());
        $payload = [
            'textQuery'           => $query,
            'maxResultCount'      => $max,
            'rankPreference'      => 'DISTANCE',
            'languageCode'        => 'ja',
            'regionCode'          => 'JP',
            'locationRestriction' => [
                'rectangle' => [
                    'low'  => ['latitude' => $box['south'], 'longitude' => $box['west']],
                    'high' => ['latitude' => $box['north'], 'longitude' => $box['east']],
                ],
            ],
        ];
        $res = chatPublicDataCachedPostJson(
            $db,
            'google_places',
            'https://places.googleapis.com/v1/places:searchText',
            $payload,
            [
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => propertyMapPlacesFieldMask('places'),
            ],
            PROPERTY_MAP_PLACES_TTL
        );
        if (empty($res['ok']) || !is_array($res['data'])) {
            if (!empty($res['error']) || (int)($res['status'] ?? 0) >= 400) {
                error_log('[property-map] places searchText error: status=' . ($res['status'] ?? 'n/a') . ' error=' . ($res['error'] ?? ''));
            }
            return ['items' => [], 'ok' => false, 'truncated' => false];
        }
        $places = is_array($res['data']['places'] ?? null) ? $res['data']['places'] : [];
        $items = [];
        $radius = propertyMapRadius();
        foreach ($places as $p) {
            if (!is_array($p)) continue;
            $n = propertyMapNormalizePlace($p);
            if (!$n) continue;
            // 矩形の四隅にはみ出した施設を落として、半径での検索範囲に揃える。
            if (propertyMapDistanceM($lat, $lng, $n['lat'], $n['lng']) > $radius) continue;
            $items[] = $n;
        }
        return ['items' => $items, 'ok' => true, 'truncated' => count($places) >= $max];
    }
}

if (!function_exists('propertyMapBoundingBox')) {
    /** 中心と半径（m）から、その円に外接する緯度経度の矩形を求める。 */
    function propertyMapBoundingBox(float $lat, float $lng, int $radiusM): array
    {
        $dLat = $radiusM / 111320.0;
        $cos = max(0.01, cos(deg2rad($lat)));
        $dLng = $radiusM / (111320.0 * $cos);
        return [
            'south' => propertyMapRound($lat - $dLat),
            'north' => propertyMapRound($lat + $dLat),
            'west'  => propertyMapRound($lng - $dLng),
            'east'  => propertyMapRound($lng + $dLng),
        ];
    }
}

if (!function_exists('propertyMapRound')) {
    /**
     * 検索中心の座標をおよそ10m単位に丸める。
     * 同じ物件・近接した物件のリクエストが同じキャッシュキーに当たるようにして、
     * Places の呼び出し回数を減らすため（§9 コスト抑制）。
     */
    function propertyMapRound(float $v): float
    {
        return round($v, 4);
    }
}

if (!function_exists('propertyMapClassify')) {
    /**
     * Places の結果を10カテゴリーのどれかへ割り当てる。該当なしは null。
     * Places には「学習塾」の type が無く school として返るため、施設名で学習塾を切り分ける。
     */
    function propertyMapClassify(array $item): ?string
    {
        $types = array_flip($item['types'] ?? []);
        $map = propertyMapPlaceTypes();
        $hasAny = static function (array $list) use ($types) {
            foreach ($list as $t) if (isset($types[$t])) return true;
            return false;
        };
        if ($hasAny($map['station']))  return 'station';
        if ($hasAny($map['store']))    return 'store';
        if ($hasAny($map['hospital'])) return 'hospital';
        if ($hasAny($map['caution']))  return 'caution';
        if ($hasAny($map['school']))   return propertyMapSchoolOrCram($item['types'] ?? [], (string)($item['name'] ?? ''));
        if ($hasAny($map['restaurant'])) return 'restaurant';
        return null;
    }
}

if (!function_exists('propertyMapSchoolOrCram')) {
    /**
     * 「学校（全て）」と「学習塾」の切り分け。
     *
     * Google Places には学習塾に対応する type が無く、SAPIX・早稲田アカデミー・日能研などは
     * 汎用の school として返ってくる。そのため
     *   ① 名称に正式な学校を示す語（小学校・中学校・高校・大学・幼稚園・保育園 等）があれば「学校」
     *   ② 名称に塾・ゼミ・予備校 等があれば「学習塾」
     *   ③ Places が学校種別（primary_school / university / preschool 等）を持っていれば「学校」
     *   ④ 種別が汎用の school だけで学校らしい語も無いものは「学習塾」（例: SAPIX中野校）
     * の順で判定する。
     */
    function propertyMapSchoolOrCram(array $types, string $name): string
    {
        if (preg_match('/(小学校|中学校|高等学校|高校|中等教育学校|特別支援学校|専門学校|高等専門学校|大学|短期大学|幼稚園|保育園|保育所|こども園|学園)/u', $name)) {
            return 'school';
        }
        if (preg_match('/(塾|ゼミ|予備校|進学教室|学習教室|個別指導)/u', $name)) {
            return 'cram';
        }
        foreach (['preschool', 'primary_school', 'secondary_school', 'university', 'child_care_agency'] as $t) {
            if (in_array($t, $types, true)) return 'school';
        }
        return 'cram';
    }
}

if (!function_exists('propertyMapCautionQueries')) {
    /**
     * 「周辺の注意施設」のうち Places の type では取得できないもの（§8⑩）。
     * Googleが実在の施設として持っているものだけを名前で引く。見つからなければ何も出さない。
     */
    function propertyMapCautionQueries(): array
    {
        return ['清掃工場 廃棄物処理施設', '下水処理場 水再生センター', '刑務所 拘置所', '変電所'];
    }
}

if (!function_exists('propertyMapFetchPlacesGroup')) {
    /**
     * §9 のグループ単位のまとめ取得。
     *  A: 駅／バス停 ＋ スーパー／コンビニ
     *  B: 学校（全て）＋ 学習塾 ＋ 病院
     *  C: レストラン ＋ 周辺の注意施設
     *
     * $wanted（ユーザーが実際に押したカテゴリー）は必ず十分な件数を返せるようにし、
     * まとめ取得で不足していた場合だけ、そのカテゴリーに絞った追加検索を行う
     * （§9「表示品質を落としてまでAPI回数を減らさないでください」）。
     *
     * 返り値: [カテゴリー => ['items' => [...], 'sufficient' => bool]]
     * sufficient=false のカテゴリーは、後からそのボタンが押されたときに再取得してよい印。
     */
    function propertyMapFetchPlacesGroup(PDO $db, float $lat, float $lng, string $group, string $wanted): array
    {
        $defs = propertyMapCategoryDefs();
        $typeMap = propertyMapPlaceTypes();
        $members = [];
        foreach ($defs as $key => $def) {
            if (($def['group'] ?? null) === $group) $members[] = $key;
        }
        if (empty($members)) return [];

        // グループの type をまとめて1回で検索する。
        $types = [];
        foreach ($members as $key) {
            if (!empty($typeMap[$key])) $types = array_merge($types, $typeMap[$key]);
        }
        $nearby = propertyMapPlacesNearby($db, $lat, $lng, $types);

        $buckets = array_fill_keys($members, []);
        $seen = [];
        $push = function (array $item, ?string $cat) use (&$buckets, &$seen, $members) {
            if ($cat === null || !in_array($cat, $members, true)) return;
            $id = $item['place_id'] !== '' ? $item['place_id'] : ($cat . '|' . $item['name'] . '|' . $item['lat'] . '|' . $item['lng']);
            if (isset($seen[$id])) return;
            $seen[$id] = true;
            unset($item['types']);
            $buckets[$cat][] = $item;
        };
        foreach ($nearby['items'] as $item) {
            $push($item, propertyMapClassify($item));
        }

        // 学習塾は type が無いため、グループBのまとめ取得と同時にテキスト検索で取得する。
        if (in_array('cram', $members, true)) {
            $cram = propertyMapPlacesTextSearch($db, $lat, $lng, '学習塾');
            foreach ($cram['items'] as $item) $push($item, 'cram');
        }

        // まとめ取得の結果が上限に達していない＝円内を取り切っている（どのカテゴリーも追加検索不要）。
        $sawEverything = $nearby['ok'] && !$nearby['truncated'];

        $out = [];
        foreach ($members as $key) {
            // 「追加検索しても増えない」と言い切れるときだけ取得済みとする。
            //  ・まとめ取得が上限に達していない＝円内を取り切っている
            //  ・そのカテゴリーだけで既に表示上限まで集まっている
            // 逆に、上限に達したまとめ取得で件数が少ないカテゴリーは、他のカテゴリーに
            // 押し出されただけの可能性があるため、そのボタンが押された時に絞って取り直す
            //（§9「表示品質を落としてまでAPI回数を減らさないでください」）。
            $out[$key] = [
                'items'      => $buckets[$key],
                'sufficient' => $sawEverything || count($buckets[$key]) >= propertyMapCategoryLimit($key),
            ];
        }
        // 注意施設は type で取れない種類（清掃工場・下水処理場・刑務所・変電所）の追加取得が
        // 済むまで「取得済み」にしない。レストランのついでに取れた墓地・葬祭施設だけで確定させると、
        // 後から「周辺の注意施設」を押しても不足したまま再取得されなくなるため。
        if (isset($out['caution']) && $wanted !== 'caution') $out['caution']['sufficient'] = false;

        // 押されたカテゴリーが不足しているときだけ、そのカテゴリーに絞って追加検索する。
        if (isset($out[$wanted]) && !$out[$wanted]['sufficient'] && !empty($typeMap[$wanted])) {
            $extra = propertyMapPlacesNearby($db, $lat, $lng, $typeMap[$wanted]);
            foreach ($extra['items'] as $item) $push($item, $wanted);
            $out[$wanted]['items'] = $buckets[$wanted];
            if ($extra['ok']) $out[$wanted]['sufficient'] = true;
        }

        // 注意施設は type で取れないもの（清掃工場・下水処理場・刑務所・変電所）を
        // 押されたときだけ追加で拾う（毎回引くと呼び出し回数が増えるため）。
        if ($wanted === 'caution' && isset($out['caution'])) {
            foreach (propertyMapCautionQueries() as $q) {
                $res = propertyMapPlacesTextSearch($db, $lat, $lng, $q, 5);
                foreach ($res['items'] as $item) $push($item, 'caution');
            }
            $out['caution']['items'] = $buckets['caution'];
            $out['caution']['sufficient'] = true;
        }

        // 表示件数の上限（レストランは近い順に最大10件・§8⑧）。
        foreach ($out as $key => $bucket) {
            $out[$key]['items'] = array_slice($bucket['items'], 0, propertyMapCategoryLimit($key));
        }
        return $out;
    }
}

/* ──────────────────────────────────────────────────────────
 * 国土交通省「不動産情報ライブラリ」（§8① / §8⑥ / §8⑨）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('propertyMapReinfoTiles')) {
    /**
     * 不動産情報ライブラリのGISタイル（XYZ）から GeoJSON feature を集める。
     * $ring=true で中心タイルの3x3を取得し、地図に表示される範囲をひととおり覆う。
     * 返り値: ['features' => [...], 'ok' => bool]
     */
    function propertyMapReinfoTiles(PDO $db, string $code, float $lat, float $lng, int $z, bool $ring, array $extraQuery = []): array
    {
        if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') return ['features' => [], 'ok' => false];
        $center = chatGeoLatLonToTile($lat, $lng, $z);
        $offsets = $ring
            ? [[0, 0], [-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [-1, 1], [1, -1], [1, 1]]
            : [[0, 0]];
        $features = [];
        $ok = false;
        foreach ($offsets as $off) {
            $query = array_merge([
                'response_format' => 'geojson',
                'z' => $z,
                'x' => $center['x'] + $off[0],
                'y' => $center['y'] + $off[1],
            ], $extraQuery);
            $url = 'https://www.reinfolib.mlit.go.jp/ex-api/external/' . $code . '?' . http_build_query($query);
            $res = chatPublicDataCachedGet($db, 'reinfolib', $url, ['Ocp-Apim-Subscription-Key' => REINFOLIB_API_KEY], PROPERTY_MAP_REINFO_TTL);
            if (empty($res['ok']) || !is_array($res['data'])) continue;
            $ok = true;
            $f = $res['data']['features'] ?? [];
            if (is_array($f) && !empty($f)) $features = array_merge($features, $f);
            if (count($features) >= 800) break;   // 描画量の上限（地図が重くならないように）
        }
        return ['features' => $features, 'ok' => $ok];
    }
}

if (!function_exists('propertyMapHazardDefs')) {
    /**
     * §8① ハザード情報。ピンではなく該当エリアを色分けして表示する4種類。
     * データは国土交通省「不動産情報ライブラリ」。
     */
    function propertyMapHazardDefs(): array
    {
        return [
            'flood'    => ['code' => 'XKT026', 'label' => '洪水浸水想定区域', 'color' => '#2d6cdf', 'z' => 15],
            'landslide' => ['code' => 'XKT029', 'label' => '土砂災害警戒区域', 'color' => '#d9622b', 'z' => 15],
            'hightide' => ['code' => 'XKT027', 'label' => '高潮浸水想定区域', 'color' => '#0f9b8e', 'z' => 15],
            'tsunami'  => ['code' => 'XKT028', 'label' => '津波浸水想定',     'color' => '#7b4bd1', 'z' => 15],
        ];
    }
}

if (!function_exists('propertyMapHazardLayers')) {
    /**
     * ハザード4種類の該当エリア（ポリゴン）を地図描画用に返す。
     * 各レイヤー: ['key','label','color','polygons'=>[['rings'=>[[ [lat,lng],... ]],'note'=>'']]]
     */
    function propertyMapHazardLayers(PDO $db, float $lat, float $lng): array
    {
        $layers = [];
        foreach (propertyMapHazardDefs() as $key => $def) {
            $res = propertyMapReinfoTiles($db, $def['code'], $lat, $lng, (int)$def['z'], true);
            $polygons = [];
            foreach ($res['features'] as $f) {
                if (!is_array($f)) continue;
                $rings = propertyMapGeometryRings($f['geometry'] ?? null);
                if (empty($rings)) continue;
                foreach ($rings as $ring) {
                    $polygons[] = ['ring' => $ring, 'note' => propertyMapHazardNote($f['properties'] ?? [])];
                    if (count($polygons) >= 300) break 2;
                }
            }
            $layers[] = [
                'key'      => $key,
                'label'    => $def['label'],
                'color'    => $def['color'],
                'polygons' => $polygons,
            ];
        }
        return $layers;
    }
}

if (!function_exists('propertyMapHazardNote')) {
    /** ハザードのポリゴンをタップしたときに出す短い説明（河川名・浸水深ランク等）。 */
    function propertyMapHazardNote(array $props): string
    {
        $parts = [];
        $river = trim((string)($props['A31a_202'] ?? $props['A31_001'] ?? ''));
        if ($river !== '') $parts[] = $river;
        $rank = trim((string)($props['A31a_205'] ?? ''));
        if ($rank !== '') $parts[] = '浸水深ランク' . $rank;
        if (empty($parts)) {
            foreach ($props as $v) {
                if (is_string($v) && $v !== '' && preg_match('/[ぁ-んァ-ン一-龥]/u', $v) && mb_strlen($v) <= 30) {
                    $parts[] = $v;
                    break;
                }
            }
        }
        return implode('／', $parts);
    }
}

if (!function_exists('propertyMapGeometryRings')) {
    /** GeoJSON の Polygon / MultiPolygon → [[ ['lat'=>..,'lng'=>..], ... ], ...]（外周のみ）。 */
    function propertyMapGeometryRings($geom): array
    {
        if (!is_array($geom)) return [];
        $type = $geom['type'] ?? '';
        $coords = $geom['coordinates'] ?? null;
        if (!is_array($coords)) return [];
        $toRing = static function ($ring) {
            $out = [];
            if (!is_array($ring)) return $out;
            // 頂点が多すぎるポリゴンは間引く（形は保ったまま描画を軽くする）。
            $step = max(1, (int)ceil(count($ring) / 400));
            $i = 0;
            foreach ($ring as $pt) {
                if (($i++ % $step) !== 0) continue;
                if (!isset($pt[0], $pt[1])) continue;
                $out[] = ['lat' => (float)$pt[1], 'lng' => (float)$pt[0]];
            }
            return count($out) >= 3 ? $out : [];
        };
        $rings = [];
        if ($type === 'Polygon') {
            $r = $toRing($coords[0] ?? []);
            if (!empty($r)) $rings[] = $r;
        } elseif ($type === 'MultiPolygon') {
            foreach ($coords as $poly) {
                if (!is_array($poly)) continue;
                $r = $toRing($poly[0] ?? []);
                if (!empty($r)) $rings[] = $r;
            }
        }
        return $rings;
    }
}

if (!function_exists('propertyMapSchoolDistricts')) {
    /**
     * §8⑥ 学校（学区）。その物件の指定小学校区・指定中学校区のポリゴンと学校名を返す。
     * 地点を含むポリゴンだけを採用し、見つからない場合は「確認できませんでした」とする
     * （自治体が公開していない場合があるため、近くの学校名で代用しない）。
     */
    function propertyMapSchoolDistricts(PDO $db, float $lat, float $lng): array
    {
        $defs = [
            ['key' => 'elementary', 'code' => 'XKT004', 'label' => '指定小学校', 'hint' => '小学校', 'color' => '#1f9d57'],
            ['key' => 'junior',     'code' => 'XKT005', 'label' => '指定中学校', 'hint' => '中学校', 'color' => '#f08a24'],
        ];
        $out = [];
        foreach ($defs as $def) {
            $res = propertyMapReinfoTiles($db, $def['code'], $lat, $lng, 14, false);
            $matched = null;
            foreach ($res['features'] as $f) {
                if (!is_array($f)) continue;
                if (chatGeoPointInFeature($lng, $lat, $f['geometry'] ?? null)) { $matched = $f; break; }
            }
            $entry = [
                'key'      => $def['key'],
                'label'    => $def['label'],
                'color'    => $def['color'],
                'name'     => '',
                'polygons' => [],
            ];
            if ($matched) {
                $entry['name'] = propertyMapFeatureName($matched['properties'] ?? [], ['A27_005', 'A27_004', 'name', 'name_ja'], $def['hint']);
                foreach (propertyMapGeometryRings($matched['geometry'] ?? null) as $ring) {
                    $entry['polygons'][] = ['ring' => $ring, 'note' => $entry['name']];
                }
            }
            $out[] = $entry;
        }
        return $out;
    }
}

if (!function_exists('propertyMapShelters')) {
    /**
     * §8⑨ 指定緊急避難場所（不動産情報ライブラリ XGT001）。
     * 半径 propertyMapRadius() 以内のものだけを近い順に返す。
     */
    function propertyMapShelters(PDO $db, float $lat, float $lng): array
    {
        $res = propertyMapReinfoTiles($db, 'XGT001', $lat, $lng, 14, true);
        $radius = propertyMapRadius();
        $rows = [];
        foreach ($res['features'] as $f) {
            if (!is_array($f)) continue;
            $c = $f['geometry']['coordinates'] ?? null;
            if (!is_array($c) || !isset($c[0], $c[1])) continue;
            $fLng = (float)$c[0];
            $fLat = (float)$c[1];
            $d = propertyMapDistanceM($lat, $lng, $fLat, $fLng);
            if ($d > $radius) continue;
            $name = propertyMapFeatureName($f['properties'] ?? [], ['P20_002', 'P20_004', 'name', 'name_ja'], '');
            if ($name === '') continue;   // 施設名が取れないものは推測で補わない（§8⑩）
            $rows[] = [
                'd'    => $d,
                'item' => [
                    'name'     => $name,
                    'lat'      => $fLat,
                    'lng'      => $fLng,
                    'place_id' => '',
                    'map_url'  => propertyMapGoogleUrl('', $fLat, $fLng),
                ],
            ];
        }
        usort($rows, static function ($a, $b) { return $a['d'] <=> $b['d']; });
        $items = [];
        $seen = [];
        foreach ($rows as $r) {
            $k = $r['item']['name'] . '|' . round($r['item']['lat'], 5) . '|' . round($r['item']['lng'], 5);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $items[] = $r['item'];
            if (count($items) >= 20) break;
        }
        return $items;
    }
}

if (!function_exists('propertyMapFeatureName')) {
    /**
     * GeoJSON のプロパティから施設名・学校名を取り出す。
     * ①優先キー → ②name 系のキー → ③$hint（「小学校」等）で終わる値 の順に探し、
     * どれにも当たらなければ空文字を返す（推測でそれらしい値を作らない）。
     */
    function propertyMapFeatureName(array $props, array $preferKeys = [], string $hint = ''): string
    {
        foreach ($preferKeys as $k) {
            if (isset($props[$k]) && is_scalar($props[$k])) {
                $v = trim((string)$props[$k]);
                if ($v !== '' && !is_numeric($v)) return $v;
            }
        }
        foreach ($props as $k => $v) {
            if (!is_scalar($v)) continue;
            $v = trim((string)$v);
            if ($v === '' || is_numeric($v)) continue;
            if (preg_match('/(^|_)(name|nm|名称|施設名)(_ja)?$/iu', (string)$k)) return $v;
        }
        if ($hint !== '') {
            foreach ($props as $v) {
                if (!is_scalar($v)) continue;
                $v = trim((string)$v);
                if ($v !== '' && mb_strpos($v, $hint) !== false && mb_strlen($v) <= 40) return $v;
            }
        }
        return '';
    }
}

if (!function_exists('propertyMapDistanceM')) {
    /**
     * 2点間のおおよその距離（メートル）。検索範囲（半径1000m）の内外判定にだけ使う内部処理で、
     * 画面には距離も徒歩時間も一切表示しない（§5 / §12）。
     */
    function propertyMapDistanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
