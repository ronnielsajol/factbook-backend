<?php
namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OutOfBoundsException;

final class DpwhQueries
{
    public function __construct(private array $snapshot, private array $options, private array $cities) {}

    public function execute(string $endpoint, array $params): array|object
    {
        $query = $this->filtered($params);
        if ($endpoint === 'map-summary') {
            $row = $query->selectRaw('COUNT(*) AS contracts, COALESCE(SUM(c.mapped), 0) AS mapped')->first();
            return ['contracts' => (int) $row->contracts, 'mapped' => (int) $row->mapped];
        }
        if ($endpoint === 'contracts') {
            $sort = $params['sort'] ?? 'year';
            if (!in_array($sort, ['budget', 'year', 'id'], true)) throw new InvalidArgumentException('Unknown sort.');
            $number = $this->number($params['page'] ?? '');
            if (is_nan($number)) $number = 0;
            $page = (int) max(0, min(9000, floor($number)));
            $total = (clone $query)->count();
            if ($sort === 'budget') $query->orderByDesc('c.budget_cents');
            if ($sort === 'year') $query->orderByDesc('c.year');
            $rows = $query->orderBy('c.id')->offset($page * 30)->limit(30)->get('c.*')->all();
            foreach ($rows as $row) {
                $row->components = (int) $row->components;
                $row->mapped = (int) $row->mapped;
                $row->budget_cents = $row->budget_cents === null ? null : (int) $row->budget_cents;
            }
            return ['total' => $total, 'page' => $page, 'pageSize' => 30, 'rows' => $rows];
        }
        if ($endpoint === 'contract') {
            $id = $params['id'] ?? '';
            if (!preg_match('/^[A-Za-z0-9-]{1,40}$/D', $id)) throw new InvalidArgumentException('Invalid contract ID.');
            $profile = DB::table('dpwh_details')->where('id', $id)->value('profile');
            if ($profile === null) throw new OutOfBoundsException('Contract not found.');
            $profile = json_decode($profile, flags: JSON_THROW_ON_ERROR);
            $profile->cityMatches = [];
            foreach (DB::table('dpwh_contract_cities')->where('contract_id', $id)->orderBy('sequence')->get() as $row) {
                $city = array_find($this->cities, fn ($value) => $value['id'] === $row->city_id);
                $evidence = json_decode($row->evidence, flags: JSON_THROW_ON_ERROR);
                $match = $city ? ['name' => $city['name']] : [];
                $profile->cityMatches[] = (object) array_merge($match, (array) $evidence);
            }
            return $profile;
        }
        if (in_array($endpoint, ['map', 'map-distribution'], true)) {
            $mode = $params['colorBy'] ?? 'status';
            if (!in_array($mode, ['status', 'year', 'type'], true)) throw new InvalidArgumentException('Unknown map color mode.');
            if ($endpoint === 'map-distribution') {
                $rows = $query->where('c.mapped', 1)->selectRaw("c.$mode AS category, COUNT(*) AS count")
                    ->groupBy("c.$mode")->orderBy("c.$mode")->get()->all();
                foreach ($rows as $row) $row->count = (int) $row->count;
                return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'count'))];
            }
            return $this->map($query, $params, $mode);
        }
        throw new OutOfBoundsException('Not found.');
    }

    private function filtered(array $params): Builder
    {
        $cityId = $params['city'] ?? '';
        $city = array_find($this->cities, fn ($value) => $value['id'] === $cityId);
        if ($cityId && $cityId !== 'All' && !$city) throw new InvalidArgumentException('Unknown city or municipality.');
        $query = DB::table('dpwh_contracts as c');
        foreach (['year' => 'years', 'region' => 'regions', 'type' => 'types', 'status' => 'statuses'] as $field => $list) {
            $value = $params[$field] ?? '';
            if (!$value || $value === 'All') continue;
            $extraRegion = $field === 'region' && array_any($this->cities, fn ($place) => $place['region'] === $value);
            if (!in_array($value, $this->snapshot[$list], true) && !$extraRegion) throw new InvalidArgumentException("Unknown $field.");
            if ($city && $field === 'region') {
                if ($value !== $city['region'] && !($value === 'Region IV-B' && $city['region'] === 'MIMAROPA Region')) {
                    throw new InvalidArgumentException('City does not belong to this region.');
                }
                continue;
            }
            if ($field === 'region' && in_array($value, ['Region IV-B', 'MIMAROPA Region'], true)) {
                $query->whereIn('c.region', ['Region IV-B', 'MIMAROPA Region']);
            } else $query->where("c.$field", $value);
        }
        $province = $params['province'] ?? '';
        if ($province && $province !== 'All') {
            if (!in_array($province, $this->options['provinces'], true)) throw new InvalidArgumentException('Unknown province.');
            if ($city) {
                if ($city['province'] !== $province) throw new InvalidArgumentException('City does not belong to this province.');
            } else {
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('dpwh_contract_provinces as province')
                    ->whereColumn('province.contract_id', 'c.id')->where('province.province', $province));
            }
        }
        if ($city) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('dpwh_contract_cities as city')
                ->whereColumn('city.contract_id', 'c.id')->where('city.city_id', $city['id']));
        }
        // Match JavaScript's 160 UTF-16 code-unit limit before tokenization.
        $text = mb_convert_encoding(substr(mb_convert_encoding($params['q'] ?? '', 'UTF-16LE', 'UTF-8'), 0, 320), 'UTF-8', 'UTF-16LE');
        $text = preg_replace('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $text);
        if ($text !== '') {
            preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
            $tokens = array_slice($matches[0], 0, 12);
            if (!$tokens) $query->whereRaw('0=1');
            foreach (array_unique($tokens) as $token) {
                $normalized = SearchNormalizer::tokens($token);
                if (!$normalized) $query->whereRaw('0=1');
                foreach ($normalized as $term) {
                    $matches = DB::table('dpwh_search_terms as term')
                        ->join('dpwh_search_memberships as membership', 'membership.term_id', '=', 'term.term_id')
                        ->where('term.term', 'like', $term.'%')->distinct()->select('membership.contract_id');
                    $query->whereIn('c.id', fn ($q) => $q->select('contract_id')->fromSub($matches, 'matched'));
                }
            }
        }
        return $query;
    }

    private function number(string $text): float
    {
        if (trim($text) === '') return 0;
        if (in_array($text, ['Infinity', '+Infinity'], true)) return INF;
        if ($text === '-Infinity') return -INF;
        if (preg_match('/^0([xob])([0-9a-f]+)$/iD', $text, $parts)) {
            $base = match (strtolower($parts[1])) { 'x' => 16, 'o' => 8, 'b' => 2 };
            if (array_any(str_split(strtolower($parts[2])), fn ($digit) => strpos('0123456789abcdef', $digit) >= $base)) return NAN;
            return (float) base_convert($parts[2], $base, 10);
        }
        return is_numeric($text) ? (float) $text : NAN;
    }

    private function map(Builder $query, array $params, string $mode): array
    {
        $categories = $this->snapshot[match ($mode) { 'year' => 'years', 'type' => 'types', default => 'statuses' }];
        $parts = explode(',', $params['bounds'] ?? '116,4,127,22');
        $bounds = array_map(fn ($v) => $this->number($v), $parts);
        if (count($bounds) !== 4 || array_any($bounds, fn ($v) => !is_finite($v)) || $bounds[0] >= $bounds[2] || $bounds[1] >= $bounds[3]) {
            throw new InvalidArgumentException('Invalid map bounds.');
        }
        $zoomValue = $this->number($params['zoom'] ?? '');
        $zoom = max(3, min(18, is_nan($zoomValue) || !$zoomValue ? 5 : $zoomValue));
        $query->join('dpwh_points as p', 'p.id', '=', 'c.id')
            ->whereBetween('p.longitude', [max(116, $bounds[0]), min(127, $bounds[2])])
            ->whereBetween('p.latitude', [max(4, $bounds[1]), min(22, $bounds[3])]);
        if ($zoom >= 10) {
            $rows = $query->orderBy('p.point_id')->limit(601)->get(['p.longitude', 'p.latitude', 'p.component', 'c.id', 'c.status', 'c.type', 'c.year'])->all();
            return ['clustered' => false, 'colorBy' => $mode, 'categories' => $categories, 'truncated' => count($rows) > 600, 'rows' => array_slice($rows, 0, 600)];
        }
        $step = $zoom < 6 ? 1 : ($zoom < 8 ? 0.25 : 0.05);
        $query->selectRaw('AVG(p.longitude) AS longitude, AVG(p.latitude) AS latitude, COUNT(*) AS points, COUNT(DISTINCT c.id) AS contracts');
        foreach ($categories as $i => $category) $query->selectRaw("SUM(CASE WHEN c.$mode = ? THEN 1 ELSE 0 END) AS mix$i", [$category]);
        $query->groupByRaw("TRUNCATE(p.longitude / $step, 0), TRUNCATE(p.latitude / $step, 0)")
            ->orderByRaw("TRUNCATE(p.longitude / $step, 0), TRUNCATE(p.latitude / $step, 0)");
        $rows = $query->limit(801)->get()->all();
        $truncated = count($rows) > 800;
        $rows = array_map(function ($row) use ($categories) {
            return ['longitude' => (float) $row->longitude, 'latitude' => (float) $row->latitude,
                'points' => (int) $row->points, 'contracts' => (int) $row->contracts,
                'mix' => array_map(fn ($i) => (int) $row->{"mix$i"}, array_keys($categories))];
        }, array_slice($rows, 0, 800));
        return ['clustered' => true, 'colorBy' => $mode, 'categories' => $categories, 'truncated' => $truncated, 'rows' => $rows];
    }
}




