<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\AddressConnection;
use App\Models\AddressIgnore;
use App\Models\AddressPerson;
use App\Models\AddressTag;
use App\Models\AddressProduct;
use App\Models\BadenCity;
use App\Models\Cluster;
use App\Models\CustomerType;
use App\Models\Favorite;
use App\Models\People;
use App\Models\Product;
use App\Models\SwitzerlandCanton;
use App\Models\Tag;
use App\Models\Tender;
use App\Models\User;
use App\Models\UserEdit;
use App\Services\AddressesService;
use App\Services\GlobalHelper;
use App\Services\GlobalSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Image;
use File;
use LabscapeRoche\LabscapeRocheService;
use stdClass;
use Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

class AddressesController extends Controller
{

    public $addressIdsDefinedWhenIteration = [];

    public $addressesService;


    function index(AddressesService $addressesService)
    {
        $this->addressesService = $addressesService;

        $addressesForResponse = $this->fetchAddressesForUsaUser();

        return response()->json($addressesForResponse);
    }


    function loadAddressesPaginated(AddressesService $addressesService)
    {
        $addresses = $addressesService->loadAddressesPaginated();
        return response()->json($addresses);

//        $addresses = $this->composePaginatedResponseViaPureSql();
//        $this->addTagsToAddresses($addresses['data']);
//        $this->addFavoriteStatusToAddresses($addresses['data']);
//        if(Auth::user()->default_country === 'usa-private') {
//            $this->addGrowthOpp($addresses['data']);
//        }
//        return response()->json($addresses);
    }


    function addTagsToAddresses($addresses)
    {
        foreach ($addresses as $i => $address) {
            $sql = "SELECT GROUP_CONCAT(t2.name SEPARATOR ', ') as tags_str
                            FROM `rl_tags` as t2
                            LEFT JOIN rl_address_tags AS at2
                            ON at2.tag_id = t2.id
                            WHERE at2.address_id = $address->id
                            GROUP BY at2.address_id
                      ";

            $tag = $this->DBWithConnection()->select($sql);

            $tagsStr = !empty($tag) ? $tag[0]->tags_str : '';

            $addresses[$i]->tags_str = $tagsStr;
        };
    }


    private function addGrowthOpp($addresses)
    {
        foreach ($addresses as $i => $address) {
            $sql = "SELECT sv1.scalar_value as growthopp 
                    FROM rl_addresses as a
                    LEFT JOIN rsm_scalar_values AS sv1 
                      ON sv1.rsm_id = a.RSMf_SiteMasterId 
                      AND sv1.franchise = 'Enterprise' 
                      AND sv1.scalar_type_id = 10
                    WHERE a.id = $address->id
                    ";

            $res = $this->DBWithConnection()->select($sql);

            $growthOpp = !empty($res) ? $res[0]->growthopp : '';

            $addresses[$i]->growthopp = intval($growthOpp);
        }
    }


    function addFavoriteStatusToAddresses ($addresses)
    {
        foreach ($addresses as $i => $address) {
            $address->is_favorite = Favorite::isFavorite(Auth::user()->id, 'ADDRESS', $address->id);
        };
    }


    function preProcessGlobalSearch()
    {
        $searchStr = request()->all()['global-search'];

        $addressesCount = $this->prepareAddressesQuery()->count();
        $peopleCount = People::where('name', 'like', '%'.$searchStr.'%')->count();

        return response()->json([
            'count_addresses' => $addressesCount,
            'count_people' => $peopleCount
        ]);
    }


    function prepareAddressesQuery()
    {
        $query = Address::with('tags')
            ->with('cluster')
            ->withCount('people')
            ->with(['products' => function($q){
                $q->select('id');
                $q->orderByRaw('company, name');
            }]);

        $query = $this->composeConditions($query, request()->all());

        return $query;
    }


    function composeConditionsUsa()
    {
        $sql = '';

        $requestParams = request()->validate([
            'tag-ids' => 'array|nullable',
            'used-product-ids' => 'array|nullable',
            'type-id' => 'integer|nullable',
            'name' => 'string|nullable',
            'global-search' => 'string|nullable',
            'tl_lat' => 'numeric|nullable',
            'tl_lon' => 'numeric|nullable',
            'br_lat' => 'numeric|nullable',
            'br_lon' => 'numeric|nullable',
            'state' => 'string',
            'cluster-id' => 'integer|nullable',
            'assignee.*' => 'integer',
            'states.*' => 'integer',
            'zones' => 'array|nullable',
            'areas' => 'array|nullable',
            'baden-cities' => 'array|nullable',
            'is-favorite' => 'sometimes|required|numeric',
            'iteration' => 'sometimes|required|array',
            'scalar-types' => 'sometimes|required|array',
            'market_programs' => 'sometimes|required|array',
        ]);

        if(isset($requestParams['tag-ids'])){
            if (Auth::user()->default_country !== 'usa-private') {
                $sql .= ' AND t.id IN ('.implode(',', $requestParams['tag-ids']).')';
            }
            elseif (Auth::user()->default_country === 'usa-private') {
                $sql .= LabscapeRocheService::getAddressTagSqlCondition($requestParams['tag-ids']);
            }
        }

        if(isset($requestParams['used-product-ids'])){
            if (Auth::user()->default_country !== 'usa-private') {
                $sql .= ' AND prod.id IN ('.implode(',', $requestParams['used-product-ids']).')';
            }
    
            if (Auth::user()->default_country === 'usa-private') {
                $ids = LabscapeRocheService::selectCollectionOfAddressIdsByProducts($requestParams['used-product-ids'], $this);
    
                $idsStr = implode(',', $ids);
    
                $sql .= ' AND a.id IN ('.$idsStr.') ';
            }
        }

        if (isset($requestParams['type-id'])) {

            if($requestParams['type-id'] != -1) {
                $sql .= ' AND a.customer_status = '.$requestParams['type-id'];
            }
            else {
                $ignoredAddressIds = AddressIgnore::getUserIgnoredAddressesIds();

                $ignoredAddressIdsStr = !empty($ignoredAddressIds) ? implode(',', $ignoredAddressIds) : -1;

                $sql .= " AND a.id IN ($ignoredAddressIdsStr)";
            }
        }

        if (isset($requestParams['global-search'])) {
            $sql .= ' AND a.name LIKE %'.$requestParams['global-search'].'%';
        }

        if (isset($requestParams['name'])) {
            $sql .= ' AND a.name LIKE %'.$requestParams['name'].'%';
        }

        if (isset($requestParams['iteration'])) {

            $addressIds = $this->getAddressIdsForIteration($requestParams);

            $this->addressIdsDefinedWhenIteration = $addressIds;

            $sql .= ' AND a.id IN('.implode(',', $addressIds).')';
        }

        if (isset($requestParams['tl_lat']) && isset($requestParams['tl_lon'])) {

            $p = $requestParams;

            $sql .= " AND ( a.lat < $p[tl_lat] AND a.lat > $p[br_lat] AND a.lon > $p[tl_lon] AND a.lon < $p[br_lon] )";
        }

        if (isset($requestParams['state'])) {
            $p = $requestParams;

            $sql .= " AND  rms.physical_state = '$p[state]'";
        }

        if (isset($requestParams['cluster-id'])) {
            $sql .= ' AND a.cluster_id = '.$requestParams['cluster-id'];
        }

        if (isset($requestParams['at-least-2-companies'])) {
            $clusterIds = (new Cluster)->getClusterIdsWithAtLeast2CompaniesInCluster();

            $sql .= ' AND a.cluster_id IN ('.implode(',',$clusterIds).') ';
        }

        if (isset($requestParams['is-favorite'])) {

            $favoriteIds = Favorite::getFavoriteEntityIds('ADDRESS');

            if(empty($favoriteIds)) {
                $sql .= ' AND a.id IN (-1) ';
            }
            else {
                $sql .= ' AND a.id IN ('.implode(',',$favoriteIds).') ';
            }

        }

        if (isset($requestParams['assignee'])) {
            $sql .= ' AND a.assigned_to IN ('.implode(',', $requestParams['assignee']).')';
        }

        if (isset($requestParams['market_programs'])) {

            $ids = LabscapeRocheService::selectCollectionOfIdsByMarketPrograms($requestParams['market_programs'], $this);

            $idsStr = implode(',', $ids);

            $sql .= ' AND a.id IN ('.$idsStr.')';
        }

        if (isset($requestParams['states'])) {

            $statesSql = "SELECT abv FROM rl_usa_state_coords WHERE id IN (".implode(',', $requestParams['states']).")";

            $statesAbbrev = array_pluck($this->DBWithConnection()->select($statesSql), 'abv');

            $statesAbbrev = array_map(function($el){return "'$el'";}, $statesAbbrev);

            $sql .= ' AND rms.physical_state IN ('.implode(',', $statesAbbrev).')';
        }

        if (isset($requestParams['baden-cities']) && env('APP_SCOPE') === 'wealthscape') {

            $citiesIds = $requestParams['baden-cities'];

            $addressesIds = $this->addressesService->getAddressIdsInsideBadenRegion($citiesIds);

            if(is_array($citiesIds) && in_array(-2, $citiesIds)) {
                $addressesIds2 = $this->addressesService->getAddressIdsOutsideBaden();
            }

            $addressesIds = array_merge($addressesIds, $addressesIds2 ?? []);

            if(empty($addressesIds)) {
                $addressesIds = [-1];
            }

            $sql .= ' AND a.id IN ('.implode(',', $addressesIds).') ';
        }

        if(Auth::user()->default_country=== 'usa-private') {
            $sql .= GlobalHelper::addUsaPrivateAddressesConditions($requestParams);
        }

        if (Auth::user()->default_country !== 'usa-private' && array_key_exists('scalar-types', $requestParams)) {

            $addressIds = (new AddressesService())->selectCollectionOfAddressIdsByScalarTypes($requestParams['scalar-types']);

            $sql .= ' AND a.id IN ('.implode(',', $addressIds).')';
        }

        return $sql;
    }


    function composeConditions($query, $requestParams)
    {

        if (isset($requestParams['sort-by'])) {

            $field = explode('-',$requestParams['sort-by'])[0];
            $direction = explode('-',$requestParams['sort-by'])[1];

            if($field == 'people') {
                $field .= '_count';
            }
            else if($field == 'products') {
                $query->withCount('products');
                $field .= '_count';
            }

            $query->orderBy($field,$direction);
        }

        if (isset($requestParams['tag-ids'])) {
            $query->whereHas('tags', function ($q) use ($requestParams) {
                $q->whereIn('id', $requestParams['tag-ids']);
            });
        }

        if (isset($requestParams['used-product-ids'])) {
            $query->whereHas('products', function ($q) use ($requestParams) {
                $q->whereIn('id', $requestParams['used-product-ids']);
            });
        }

        if (isset($requestParams['type-id'])) {
            $query->whereHas('customerType', function ($q) use ($requestParams) {
                $q->where('id', $requestParams['type-id']);
            });
        }

        if (isset($requestParams['global-search'])) {
            $query->where('rl_addresses.name', 'LIKE', '%'.$requestParams['global-search'].'%');
        }

        if (isset($requestParams['name'])) {
            $query->where('rl_addresses.name', 'LIKE', '%'.$requestParams['name'].'%');
        }

        if (isset($requestParams['iteration'])) {

            $addressIds = $this->getAddressIdsForIteration($requestParams);

            $query->whereIn('rl_addresses.id', $addressIds);

            $query->orderByRaw('FIELD(rl_addresses.id, '. implode(', ', $addressIds).')');
        }

        if (isset($requestParams['address-ids'])) {
            $query->whereIn('rl_addresses.id', explode(',',$requestParams['address-ids']));
        }

        if (isset($requestParams['cluster-id'])) {
            $query->where('cluster_id', $requestParams['cluster-id']);
        }

        if (isset($requestParams['at-least-2-companies'])) {
            $clusterIds = (new Cluster)->getClusterIdsWithAtLeast2CompaniesInCluster();

            $query->whereIn('rl_addresses.cluster_id', $clusterIds);
        }

        return $query;
    }


    function getAddressIdsForIteration($requestParams)
    {
        $GSS = new GlobalSearchService();
        $groupedSearchIterations = $GSS->groupSearchIterationsByEntity($requestParams['iteration']);

        $isWithLevenstein = isset($requestParams['with-levenstein']);

        $addressIds = $GSS->searchForAddressesIds($groupedSearchIterations, $isWithLevenstein, GlobalHelper::defineIsAddressesOrClustersShouldBeOrdered());

        return $addressIds;
    }


    function loadFilterValues()
    {
        if(Auth::user()->default_country === 'usa-private') {
            $rocheService = new LabscapeRocheService($this);
            $tags = $rocheService->getFilterTagValues();
            $relationalTags = [];

            $products = $rocheService->getProductFilterValues();
            $relationalProducts = $rocheService->getRelationalProductFilterValues();

            $sortByOptions = $rocheService->getSortByFilterOptions();
        }
        else {
            $tags = Tag::get(['id', 'name']);

            $relationalTags = collect($this->getRelationalTagsParent());

            $relationalTags->each(function ($tag) {
                $tag->childProducts = Tag::where('parent_tag_id', $tag->id)
                    ->orderBy('name')
                    ->groupBy('rl_tags.id')
                    ->get(['id', 'name']);
            });

            $products = Product::orderByRaw('company, name')->get();
            $relationalProducts = Product::where(function ($q){
                $q->whereRaw("rl_products.name IS NULL OR rl_products.name = ''");
            })
                ->orderByRaw('company, name')
                ->get();

            $relationalProducts->each(function ($product) {
                $product->childProducts = Product::where('company', $product->company)
                    ->orderByRaw('company, name')
                    ->get();
            });

            $sortByOptions = $this->getSortByOptions();
        }

        $personTypes = $this->DBWithConnection()->table('rl_people_types')->get();

        $customerTypes = CustomerType::visible()->get();

        $filters = [
            'tag_list' => $tags,
            'used_product_list' => $products,
            'customer_types' => $customerTypes,
            'person_types' => $personTypes,
            'relational_products' => $relationalProducts,
            'relational_tags' => $relationalTags,
            'sort_by' => $sortByOptions
        ];

        if(GlobalHelper::isWealthscapeApp()) {

            $cantons = SwitzerlandCanton::all();

            $relationalCantons = SwitzerlandCanton::where('parent_id', 0)->get();
            $relationalCantons->each(function ($canton) {
                $canton->childProducts = SwitzerlandCanton::where('parent_id', $canton->id)
                    ->orderBy('name')
                    ->groupBy('rl_geo_hierarchy.id')
                    ->get(['id', 'name']);
            });

            $filters['location_list'] = $cantons;
            $filters['relational_locations'] = $relationalCantons;


            $filters['assignee_list'] = User::getUserListForAssigning();

            $filters['baden_cities'] = $this->composeBadenCitiesFilter();
            $filters['relational_baden_cities'] = $this->composeRelationslBadenCitiesFilter($filters['baden_cities']);

        }

        return response()->json($filters);
    }


    private function composeBadenCitiesFilter()
    {
        $cities = [];

        $badenCities = BadenCity::all();

        foreach ($badenCities as $k => $city) {
            $cities[$k]['id'] = $city->id;
            $cities[$k]['name'] = $city->city;
        }

        return $cities;
    }


    private function composeRelationslBadenCitiesFilter($badenCities)
    {
        $relationalCities = [];

        $relationalCities[] = [
            'id' => -2,
            'parent_tag_id' => 0,
            'name' => 'Others',
            'childProducts' => []
        ];

        $relationalCities[] = [
            'id' => -1,
            'parent_tag_id' => 0,
            'name' => 'Bezirk Baden',
            'childProducts' => $badenCities
        ];

        return $relationalCities;
    }


    function getSortByOptions()
    {
        $options = [
            ['value' => 'name-asc', 'label' => 'Name &uarr;'],
            ['value' => 'name-desc', 'label' => 'Name &darr;'],
            ['value' => 'people-asc', 'label' => 'Employee &uarr;'],
            ['value' => 'people-desc', 'label' => 'Employee &darr;'],
        ];

        if(env('APP_SCOPE') !== 'wealthscape') {
            $options[] = ['value' => 'products-asc', 'label' => 'Products &uarr;'];
            $options[] = ['value' => 'products-desc', 'label' => 'Products &darr;'];
        }

        return $options;
    }


    function showSimpleDetails($id)
    {
        $address = Address::find($id);

        return response()->json($address);
    }

    function getRelationalTagsParent ()
    {
        return $this->getResultFromCache("
                                SELECT t1.*
                        FROM rl_tags as t1
                        WHERE t1.parent_tag_id IS NULL OR t1.parent_tag_id = 0
                        GROUP BY t1.id
                        
                        UNION
                        
                        SELECT t2.*
                        FROM rl_tags as t2
                        JOIN rl_tags as t3
                          ON t3.parent_tag_id = t2.id
                        WHERE t2.parent_tag_id IS NULL OR t2.parent_tag_id = 0
                        GROUP BY t2.id
                        
                        ORDER BY name
                    ");
    }


    function show($address)
    {
        $address = Address::find($address);

        if(Auth::user()->default_country === 'usa-private') {
            $address->tags = LabscapeRocheService::getAddressTagsAsArrayOfObjects($address->RSMf_SiteMasterId, $this->DBWithConnection());
        }
        else {
            $address->load('tags');
        }

        $address->load([
            'tenders.purchase',
            'cluster', 
            'cluster.addresses' => function($q){
                $q->take(4);
            },
            'people' => function($query){
                $query->withCount('relationships');
                $query->groupBy('name');
                $query->orderBy('relationships_count', 'DESC');
                $query->take(4);
            },
            'products' => function ($query) {
                $query->orderByRaw('company, name');
            }
        ]);

	    if(!empty($address->cluster)) {
            $address->cluster->addresses_count = $address->cluster->getAddressMembersNumber();
        }

        if(Auth::user()->default_country == 'usa-private') {
            $this->addUsaPrivateSpecificFeatures($address);
        }

        $address->is_in_ignore_list = AddressIgnore::checkIfAddressIgnored($address->id);

	    $address = $address->toArray();

	    $address['tenders'] = Tender::where('address_id', $address['id'])->threeProductsWithMostBudgetSpent()->get();

	    $address['has_relationships_for_graph'] = Address::hasRelationshipsForGraph($address['id']);

	    $address['is_favorite'] = Favorite::isFavorite(Auth::user()->id, 'ADDRESS', $address['id']);

        return response()->json($address);
    }


    function addUsaPrivateSpecificFeatures(Address $address)
    {
        $address->awards = $this->getAddressAwards($address->id);

        $address->rms_ext = Address::getUsRmsExtValues($address->RSMf_SiteMasterId);

        if(!empty($address->cluster->addresses)){
            $address->cluster->addresses->each(function($item, $k) {
                $item->load(['scalar_values' => function($q){
                    $q->whereRaw('(scalar_type_id = 1 OR scalar_type_id= 10)');
                }]);
            });
        }

        $lrs = new LabscapeRocheService($this);

        $address->market_programs = $lrs->getMarketProgramsByRsmIdAndSiteLvl($address->RSMf_SiteMasterId, 'Site');
    }


    function updateCustomerStatus($address)
    {
        $address = Address::find($address);

        $data = request()->validate([
            'status' => 'required|integer',
        ]);

        $address->customer_status = $data['status'];
        $address->save();

        return response()->json($address, 200);
    }


    function getSimpelListOfEmployees ($addressId)
    {
        $sql = "SELECT p.id, p.name, IFNULL(ap.role, p.role) as role 
                FROM rl_address_people as ap
                JOIN rl_people as p 
                  ON p.id = ap.person_id
                WHERE ap.address_id = $addressId";

        $employees = $this->DBWithConnection()->select($sql);

        return response()->json($employees);
    }

    function loadPeopleByAddressId ($address)
    {
        $params = request()->all();

        if(isset($params['simple-list'])) {
            return $this->getSimpelListOfEmployees($address);
        }

        $address = Address::find($address);

        if(isset($params['main-person-id']) && !$this->isPersonAnEmployee($address, $params['main-person-id'])) {
            return ['data' => [], 'total' => 0];
        }

        $people = People::with('addresses')
                        ->with(['addressPerson' => function($q) use($address) {
                            $q->where('address_id', $address->id);
                        }])
                        ->withCount('relationships')
                        ->where(function ($q) use ($params) {

                            if(isset($params['name'])) {
                                $q->where('name', 'like', "%$params[name]%");
                            }
                        })
                        ->whereHas('addressPerson', function($q) use ($params){
                            if(isset($params['role'])) {

                                $role = $params['role'];

                                if($role === '--empty--') {
                                    $role = "";
                                }

                                $q->where('role', $role);
                            }
                        })
                        ->whereHas('addresses', function ($q) use ($address){
                            return $q->where('id', $address->id);
                        })
                        ->groupBy('rl_people.name')
                        ->orderBy('relationships_count', 'DESC')
                        ->paginate(10);

        return response()->json($people);
    }


    function isPersonAnEmployee($address, $personId)
    {
        $sql = "SELECT * FROM rl_address_people WHERE address_id = $address->id AND person_id = $personId";

        $result = $this->DBWithConnection()->select($sql);

        return !empty($result);
    }


    function getContactsChain($address)
    {

        $address = Address::find($address);

        $mainLabId = $address->id;

        $sqlQuery = "SELECT a2.id, a2.name, a2.cluster_id FROM rl_addresses a JOIN rl_addresses a2 WHERE (a.cluster_id = a2.cluster_id OR a2.id = ? ) AND a.id = ?";

        $cluster_labs = array_pluck($this->DBWithConnection()->select(DB::raw($sqlQuery), [$mainLabId, $mainLabId]), 'id');

        $cluster_labs_ids = implode(',', $cluster_labs);

        $sql = "SELECT * from
                (SELECT a.id, a.name, a.cluster_id, a.address FROM rl_addresses a WHERE a.id IN (" . $cluster_labs_ids . ") 
                UNION
                SELECT a2.id, a2.name, a2.cluster_id, a2.address FROM rl_addresses a  
                JOIN rl_address_people ap ON a.id = ap.address_id -- workers of main hospital
                JOIN rl_address_connections ac ON ac.from_person_id = ap.person_id -- people who know people on main hospital
                JOIN rl_address_people ap2 ON ap2.person_id = ac.to_person_id -- workplaces of people who know people on main hospital
                JOIN rl_addresses a2 ON ap2.address_id = a2.id 
                WHERE a.id IN (" . $cluster_labs_ids . ") 
                UNION 
                SELECT a2.id, a2.name, a2.cluster_id, a2.address FROM rl_addresses a  
                JOIN rl_address_people ap ON a.id = ap.address_id -- workers of main hospital
                JOIN rl_address_connections ac ON ac.to_person_id = ap.person_id -- people who know people on main hospital 
                JOIN rl_address_people ap2 ON ap2.person_id = ac.from_person_id -- workplaces of people who know people on main hospital
                JOIN rl_addresses a2 ON ap2.address_id = a2.id 
                WHERE a.id IN(" . $cluster_labs_ids . ")              
                ) related_labs ";

        $related_labs = $this->DBWithConnection()->select(DB::raw($sql));

        $related_labs_ids = "";
        $first = true;
        foreach ($related_labs as $lab){
            if ($first){
                $first = false;
            }else{
                $related_labs_ids = $related_labs_ids . ",";
            }
            $related_labs_ids = $related_labs_ids . $lab ->id;
        }

        $sql = "SELECT rl.id, rl.name, ap.address_id, pt.name as 'workerType' 
                FROM rl_people rl  
                JOIN rl_address_people ap ON ap.person_id = rl.id 
                JOIN rl_people_types pt ON rl.type_id = pt.id  
                WHERE ap.address_id IN (" . $related_labs_ids . ")";

        $lab_workers = $this->DBWithConnection()->select(DB::raw($sql));


        $related_people = [];
        if ($related_labs_ids != ""){
            $sql = "SELECT p.id, p.name, ap.address_id FROM rl_address_people ap JOIN rl_people p ON ap.person_id = p.id  
                    WHERE ap.address_id IN (" . $related_labs_ids . ")";

            $related_people = $this->DBWithConnection()->select(DB::raw($sql));
        }

        // get the relations from related people
        $first = true;
        $related_people_ids = "";
        foreach ($related_people as $p){
            if ($first){
                $first = false;
            }else{
                $related_people_ids = $related_people_ids . ",";
            }
            $related_people_ids = $related_people_ids . $p->id;
        }

        // get relationships and descriptions
        $people_relationships = [];
        if ($related_people_ids != ""){
            $sql = "SELECT ac.from_person_id, ac.to_person_id, ac.edge_weight, act.id as 'connection_type' 
              FROM rl_address_connections ac LEFT JOIN rl_address_connection_types act on ac.edge_type = act.id 
            WHERE ac.from_person_id IN (" . $related_people_ids . ") AND ac.to_person_id IN (" . $related_people_ids . ") ";

            $people_relationships = $this->DBWithConnection()->select(DB::raw($sql));
        }

        $result = [ 'related_labs' => $related_labs, 'related_people' => $related_people, 'relationships' => $people_relationships, 'workers' => $lab_workers ];

        return response()->json($result);
    }


    function getClusterMembersPaginated($address)
    {
        $address = Address::find($address);

        $search = request()->search;

        $clusterAddressesQuery = Address::where('cluster_id', $address->cluster_id);

        if(!empty($search)) {
            $clusterAddressesQuery = $clusterAddressesQuery->where('name', 'like', '%'.$search.'%');
        }

        if(Auth::user()->default_country === 'usa-private') {
            $clusterAddressesQuery->with('scalar_values');
            
            if(Auth::user()->isAclUser()) {
                $aclAddressIds = Auth::user()->getAddressIdsViaAclAddressRsmIds();
    
                $clusterAddressesQuery = $clusterAddressesQuery->whereIn('id', $aclAddressIds);
            }
        }


        $clusterAddresses = $clusterAddressesQuery->paginate(10);

        return response()->json($clusterAddresses, 200);
    }


    function getClusterStaffPaginated($address)
    {
        $address = Address::find($address);

        $query = People::with('addresses')
            ->whereHas('addresses', function ($q) use ($address) {
                $q->where('cluster_id', $address->cluster_id);
            });

        $query = $this->composeClusterStuffConditions($query, request()->all());

        $clusterStaff = $query->orderByRaw('name')
            ->paginate(10);

        return response()->json($clusterStaff);
    }


    function composeClusterStuffConditions($query, $params)
    {
        if (isset($params['name'])) {
            $query->where('rl_people.name', 'like', '%'.$params['name'].'%');
        }

        if (isset($params['type'])) {
            $query->where('rl_people.type_id', $params['type']);
        }

        return $query;
    }


    function getClusterProductsPaginated($address)
    {
        $address = Address::find($address);

        $products = Product::with([
                'addresses' => function ($query) use ($address) {
                    $query->where('cluster_id', $address->cluster_id);
                }
            ])
            ->whereHas('addresses', function ($q) use ($address) {
                $q->where('cluster_id', $address->cluster_id);
            })
            ->orderByRaw('company, name')
            ->paginate(10);

        return response()->json($products, 200);
    }

    /**
     * update Address
     */
    public function updateAddressDetails($address)
    {
        $address = Address::find($address);

        $params = request()->only(['name', 'address', 'url', 'phone']);

        $params['updated_by'] = 'labscape';

        $address->update($params);

        $tags = request()->get('tags');

        $ids = [];

        foreach ($tags as $tag) {

            $tagName = isset($tag['name']) ? $tag['name'] : $tag;

            if ( ! Tag::whereName($tagName)->first()) {
                $newTag = new Tag();
                $newTag->name = $tagName;
                $newTag->save();
                $ids[] = $newTag->id;
            } else {
                $ids[] = $tag['id'];
            }
        }

        UserEdit::logManyToMany($address, 'rl_tags', $ids, $address->tags()->pluck('id')->toArray());

        $address->tags()->sync($ids);

        return response()->json($address, 200);
    }

    /**
     * get all tags
     */
    public function loadAllTags($address)
    {
        $tags = Tag::all();

        return response()->json($tags, 200);
    }

    /**
     * get selected tags for address
     */
    public function loadSelectedTags($address)
    {
        $address = Address::find($address);

        $selectedTags = $address->load('tags')->tags;

        return response()->json($selectedTags, 200);
    }

    /**
     * get all clusters
     */
    public function getClusters()
    {
        $clusters = Cluster::get();
        return response()->json($clusters, 200);
    }

    /**
     * update clusters
     */
    public function updateClusters($address)
    {
        $address = Address::find($address);

        $oldClusterId = $address->cluster_id;
        $address->cluster_id = request()->get('cluster_id');

        if(Auth::user()->default_country === 'usa-private') {
            $address->updated_by = 'labscape';
        }

        $address->update();
        $address->load(['cluster', 'cluster.addresses']);
        $cluster = Cluster::with('addresses')
                    ->whereId($oldClusterId)
                    ->first();
        if ($cluster) {
            if (!$cluster->addresses->count()) {
                $cluster->delete();
            }
        }

        return response()->json($address, 200);
    }

    /**
     * get all products
     */
    public function getProducts()
    {
        $products = Product::orderByRaw('company, name')->get();
        return response()->json($products, 200);
    }

    /**
     * update used products for address
     */
    public function updateProducts($address)
    {
        $address = Address::find($address);

        $selectedProducts = request('selectedProducts');

        UserEdit::logManyToMany($address, 'rl_products', $selectedProducts, $address->products()->pluck('id')->toArray());

        $address->products()->sync($selectedProducts);

        $address->load([
            'products' => function ($query) {
                $query->orderByRaw('company, name');
            }
        ]);

        return response()->json($address, 200);
    }

    /**
     * create new Product
     */
    public function createProduct ($address)
    {
        $address = Address::find($address);

        $company = trim(request('company'));
        $name = trim(request('name'));
        $description = trim(request('description'));

        if ( ! $company) {
            return response()->json([
                'status' => 'error',
                'message' => "Company field should not be empty"
            ], 200);
        }

        if ($company && ($name == "" || $name == null)) {
            $prod = Product::whereCompany($company)->where('name', '=', "")->orWhere('name', '=', null)->first();
            if ($prod) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Product with company - '$company' already exists!"
                ], 200);
            }
        }

        if ($company && $name) {
            $prod = Product::whereCompany($company)->whereName($name)->first();
            if ($prod) {
                return response()->json([
                    'status' => 'error',
                    'message' => "This product already exists!"
                ], 200);
            }
        }

        if (strlen($company) > 255 || strlen($name) > 255) {
            return response()->json([
                'status' => 'error',
                'message' => "Max count of characters is 255!"
            ], 200);
        }

        $product = new Product();
        $product->company = $company;
        $product->name = $name;
        $product->description = $description;

        $image = request()->file('image');

        if ($image) {
            $extension = $image->getClientOriginalExtension();

            if ($extension == 'jpg' || $extension == 'jpeg' || $extension == 'png') {
                $imageName = now()->format('Y-m-d-H-i-s') . '.' . $extension;

                $img = Image::make($image->getRealPath());

                $img->resize(100, 100, function ($constraint) {

                    $constraint->aspectRatio();

                })->save(storage_path("app/public/product-images/$imageName"));

                $product->image = "/product-images/$imageName";
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => "Only jpg/jpeg/png files are allowed!"
                ], 200);
            }
        }

        $product->save();

        $products = Product::orderByRaw('company, name')->get();

        $addressProduct = new AddressProduct();
        $addressProduct->address_id = $address->id;
        $addressProduct->product_id = $product->id;
        $addressProduct->save();

        $address->load([
            'products' => function ($query) {
                $query->orderByRaw('company, name');
            }
        ]);

        return response()->json([
            'products' => $products,
            'address' => $address
        ], 200);
    }

    /**
     * get all people for health system
     */
    public function getAllClusterStaff ($address)
    {
        $address = Address::find($address);

        $clusterStaff = People::with('addresses')
            ->whereHas('addresses', function ($q) use ($address) {
                $q->where('cluster_id', $address->cluster_id);
            })
            ->orderByRaw('name')
            ->get();

        return response()->json($clusterStaff, 200);
    }

    /**
     * updating lab-chain name
     */
    public function updateClusterName ($address)
    {
        $address = Address::find($address);

        $cluster = Cluster::whereId($address->cluster->id)->first();
        $cluster->name = request('clusterName');
        $cluster->save();

        return response()->json($cluster, 200);
    }

    /**
     * create new cluster if not exist
     */
    public function createCluster ($address)
    {
        $address = Address::find($address);

        $name = trim(request('name'));

        $cluster = Cluster::where('name', $name)->first();

        if ($cluster) {
            return response()->json([
                'status' => 'error',
                'message' => 'Health system already exists'
            ], 200);
        } else {
            $cluster = new Cluster();

            $cluster->name = $name;

            $cluster->save();

            $address->cluster_id = $cluster->id;

            $address->save();

            $cluster->load('addresses');

            return response()->json([
                'status' => 'success',
                'cluster' => $cluster
            ], 200);
        }
    }

    // create new relation from person to person
    public function createPersonRelation (Request $request)
    {
        $fromPersonId = request('fromPersonId');

        $toPersonId = request('toPersonId');

        $edgeType = request('edgeType');

        $edgeComment = request('edgeComment');

        $user = JWTAuth::user();

        $addressConnection = AddressConnection::where('from_person_id', $fromPersonId)
            ->where('to_person_id', $toPersonId)
            ->first();

        if ( ! empty($addressConnection)) {
            return response()->json([
                'success' => false,
                'message' => 'This connection already exists!'
            ], 200);
        }

        $addressConnection = new AddressConnection();

        $addressConnection->from_person_id = $fromPersonId;

        $addressConnection->from_address_id = null;

        $addressConnection->to_person_id = $toPersonId;

        $addressConnection->to_address_id = null;

        $addressConnection->edge_weight = 1;

        $addressConnection->edge_type = $edgeType;

        $addressConnection->edge_comment = $edgeComment;

        $addressConnection->edge_source = null;

        $addressConnection->user_id = $user->id;

        $addressConnection->save();

        return response()->json([
            'success' => true,
            'message' => 'ok'
        ], 200);
    }

    // delete relation from person to person
    public function deletePersonRelation (Request $request)
    {
        $fromPersonId = request('fromPersonId');

        $toPersonId = request('toPersonId');

        AddressConnection::where('from_person_id', $fromPersonId)
            ->where('to_person_id', $toPersonId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'ok'
        ], 200);
    }


    function defineJoinsForUSA($isForPagination = false)
    {
        $requestParams = request()->all();

        $mainSql = ' LEFT JOIN rl_address_precomputed_props as acomp ON acomp.address_id = a.id';

        if(Auth::user()->default_country == 'usa-private') {
            $mainSql .= ' LEFT JOIN rl_addresses_us_rms AS rms ON a.RSMf_SiteMasterId = rms.RSMf_SiteMasterId';

            if(isset($requestParams['scalar-types'])) {
                $mainSql .= GlobalHelper::addScalarValueJoins($requestParams);
            }

            if (isset($requestParams['zones'])
                || isset($requestParams['areas'])
                || isset($requestParams['franchises'])
                || isset($requestParams['tag-ids'])
            ) {
                $mainSql .= ' LEFT JOIN rl_addresses_us_rms_ext AS are ON a.RSMf_SiteMasterId = are.RSMf_SiteMasterId';
            }

            if(isset($requestParams['sort-by'])) {

                if($requestParams['sort-by'] === 'growthopp-desc'){
                    $mainSql .= " LEFT JOIN rsm_scalar_values AS sv1 ON sv1.rsm_id = a.RSMf_SiteMasterId AND sv1.franchise = 'Enterprise' AND sv1.scalar_type_id = 10 ";
                }
                elseif($requestParams['sort-by'] === 'actualsales-desc')
                {
                    $mainSql .= " LEFT JOIN rsm_scalar_values AS sv2 ON sv2.rsm_id = a.RSMf_SiteMasterId AND sv2.franchise = 'Enterprise' AND sv2.scalar_type_id = 11 ";
                }
            } 
        }

        if (Auth::user()->default_country !== 'usa-private' && array_key_exists('scalar-types', $requestParams)) {
            $mainSql .= ' LEFT JOIN rl_scalar_values AS sv ON a.id = sv.address_id';
        }

        if ($isForPagination) {
            $mainSql .= ' LEFT JOIN rl_clusters AS cl ON a.cluster_id = cl.id';
        }

        if(isset($requestParams['tag-ids'])) {
            $mainSql .= " LEFT JOIN rl_address_tags AS at
                            ON at.address_id = a.id
                         LEFT JOIN rl_tags AS t
                            ON t.id = at.tag_id";
        }

        if(isset($requestParams['used-product-ids'])) {
            $mainSql .= " LEFT JOIN rl_address_products AS aprod
                            ON a.id = aprod.address_id
                         LEFT JOIN rl_products AS prod
                            ON prod.id = aprod.product_id";
        }

        return $mainSql;
    }


    function countAddressesForUSA() {

        $sql = "SELECT COUNT(DISTINCT a.id) as cnt FROM rl_addresses AS a";

        $sql .= $this->defineJoinsForUSA();

        $sql .= " WHERE a.lat <> 0 AND a.lon <> 0";

        $sql .= $this->composeConditionsUsa();

        $result = $this->DBWithConnection()->select(DB::raw($sql));

        return $result[0]->cnt;
    }


    function prepareSqlForFetchAddressesWithoutGroupingByState($isForPagination = false)
    {
        $sql = "SELECT a.id, a.name, a.lat, a.lon, a.customer_status, acomp.people_count";

        if(env('APP_SCOPE') === 'wealthscape') {
            $sql .= ', a.assigned_to';
        }

        if ($isForPagination) {
            $sql .= ", a.address, cl.name as cluster_name";
        }

        if(Auth::user()->default_country === 'usa-private' && request()->get('sort-by') === 'growthopp-desc') {
            $sql .= ", sv1.scalar_value as growthopp";
        }

        if(Auth::user()->default_country === 'usa-private' && request()->get('sort-by') === 'actualsales-desc') {
            $sql .= ", sv2.scalar_value as actualsales";
        }

        $sql .= " FROM rl_addresses AS a";

        $sql .= $this->defineJoinsForUSA($isForPagination);

        $sql .= " WHERE a.lat <> 0 
                    AND a.lon <> 0 
                    AND a.lat IS NOT NULL 
                    AND a.lon IS NOT NULL";

        $sql .= $this->composeConditionsUsa();

        $sql .= ' GROUP BY a.id ';

        return $sql;
    }


    function fetchAddressesWithoutGroupingByState()
    {
        $sql = $this->prepareSqlForFetchAddressesWithoutGroupingByState();

        return $result = $this->getResultFromCache($sql);
    }


    function checkIfRequestedMin2CompaniesInCluster()
    {
        if(request()->get('at-least-2-companies')){
            $clusterIds = (new Cluster)->getClusterIdsWithAtLeast2CompaniesInCluster();

            $subSubSql = "(SELECT a0.id FROM rl_addresses AS a0 WHERE a.id = a0.id AND a0.cluster_id IN (".implode(',', $clusterIds)."))";
        }
        else {
            $subSubSql = 'a.id';
        }
        return $subSubSql;
    }


    function findAddressesAndGroupByState()
    {

        $subSubSql = $this->checkIfRequestedMin2CompaniesInCluster();

        $subSql = "SELECT rms.physical_state as state_abv, COUNT(DISTINCT $subSubSql) as total_addresses 
                FROM rl_addresses AS a";

        $subSql .= $this->defineJoinsForUSA();

        $subSql .= " WHERE a.lat <> 0 AND a.lon <> 0";

        $subSql .= $this->composeConditionsUsa();

        $subSql .= " GROUP BY rms.physical_state";

        $sql = "SELECT st.name, st.lon, st.lat, state_abv, total_addresses
            FROM ($subSql) subsql
            JOIN rl_usa_state_coords as st ON st.abv = state_abv
        ";

        return $result = $this->getResultFromCache($sql);
    }

    function addAbbreviationProp($data) {

        foreach ($data as $value) {

            $count = $value->total_addresses;

            $abbrev = $count >= 10000 ? round($count / 1000) .'k' :
                      $count >= 1000 ? round($count / 100) / 10  . 'k' : $count;


            $value->total_addresses_abbrev = (string)$abbrev;
        }

        return $data;
    }

    function getAbbreviationValue($count)
    {
        $abbrev = $count >= 10000 ? round($count / 1000) .'k' :
            $count >= 1000 ? round($count / 100) / 10  . 'k' : $count;

        return $abbrev;
    }

    function fetchAddressesForUsaUser()
    {
        $foundAddressNumber = $this->countAddressesForUSA();

        if($foundAddressNumber > 10000 && !request()->get('state') && Auth::user()->default_country == 'usa-private') {
            $data = $this->findAddressesAndGroupByState();
            $result['data'] = $this->addAbbreviationProp($data);
            $result['groupedByState'] = true;
        }
        elseif($foundAddressNumber > 10000) {
            $result['data'] = $this->findAddressesAndGroupByLatLonTile();
            $result['groupedByState'] = true;
        }
        else {
            $result = $this->fetchAddressesWithoutGroupingByState();
        }

        return $result;
    }


    function composePaginatedResponseViaPureSql()
    {
        $total = $this->countAddressesForUSA();

        $sql = $this->prepareSqlForFetchAddressesWithoutGroupingByState(true);

        $sql .= $this->composeOrderByForAddressesWithoutGroupingByState(request()->all());

        $sql .= ' LIMIT 20' . ' OFFSET ' . $this->getOffsetForPagination();

        $result = $this->getResultFromCache($sql);

        $data = [
            'total' => $total,
            'data' => $result
        ];

        return $data;
    }

    function getOffsetForPagination() {
        return 20 * (intval(request()->page) - 1);
    }


    function composeOrderByForAddressesWithoutGroupingByState($params)
    {
        $sql = '';

        if (isset($params['sort-by'])) {

            $field = explode('-',$params['sort-by'])[0];
            $direction = explode('-',$params['sort-by'])[1];

            if($field == 'people') {
                $field .= '_count';
            }
            else if($field == 'products') {
                $field .= '_count';
            }

            $sql = " ORDER BY $field $direction";
        }
        else {
            $sql = " ORDER BY people_count DESC, a.id DESC";
        }

        if (isset($params['iteration'])) {
            $sql = ' ORDER BY FIELD(a.id, '. implode(', ', $this->addressIdsDefinedWhenIteration).')';
        }

        return $sql;
    }


    function getStaffByRoleForChart($id)
    {
        $sql = "SELECT person_id, IF( IF(ap.role <> '' || ap.role IS NOT NUll, ap.role, p.role) = '', 'Others', ap.role) as role FROM rl_people as p
                JOIN rl_address_people as ap ON ap.person_id = p.id
                WHERE ap.address_id = $id";

        $result = $this->getResultFromCache($sql);

        $total = count($result);

        $rolesCount = [];

        foreach ($result as $record) {
            if(empty($rolesCount[$record->role])) {
                $rolesCount[$record->role] = 0;
            }

            ++$rolesCount[$record->role];
        }

        $groupedData = ['Others' => 0];

        foreach ($rolesCount as $role => $occur) {

            $rolesCount[$role] = [];

            $rolesCount[$role]['percentage'] = $occur / $total * 100;
            $rolesCount[$role]['occur'] = $occur;
        }

        foreach ($rolesCount as $role => $values) {

            if($role === 'Others' || $values['percentage'] < 3) {
                $groupedData['Others'] += $values['occur'];
            }
            else {
                $groupedData[$role] = $values['occur'];
            }
        }

        $graphData = [];

        foreach ($groupedData as $role => $occur) {
            $graphData[] = [$role, $occur];
        }

        array_unshift($graphData, ['Role', '%']);

        return response()->json($graphData, 200);
    }


    function getAddressAwards($id)
    {
        $lrs = new LabscapeRocheService($this);

        return $lrs->getAddressAwards($id);
    }


    function getEmployeeRoleList($id)
    {
        $sql = "SELECT DISTINCT(IF(role <> '', role, '--empty--')) as role FROM rl_address_people WHERE address_id = ? ORDER BY role ASC";

        $result = $this->DBWithConnection()->select($sql, [$id]);

        $roleList = array_pluck($result, 'role');

        return response()->json($roleList, 200);
    }


    function awardsDataForGaugeChart($id)
    {
        $address = Address::find($id);

        $graphMaxSql = 'SELECT ROUND(MAX(award_score), 1) as max_score FROM us_influencers_awards WHERE award_category_id = 4';
        $graphMax = $this->getResultFromCache($graphMaxSql)[0]->max_score;

        $graphDataSql = "SELECT ROUND(MAX(award_score), 2) as award_score
                            FROM us_influencers_awards 
                            WHERE award_category_id = 4 
                            AND address_id = $address->id";

        $awardScore = $this->getResultFromCache($graphDataSql)[0]->award_score;

        $graphData = ['Centrality', $awardScore];

        return response()->json([
            'data' => $graphData,
            'graphMax' => $graphMax
        ], 200);
    }


    function getDataForGrowthTrajectoryGauge($id)
    {
        $address = Address::find($id);

        $graphMaxSql = 'SELECT ROUND(MAX(score), 1) as max_score FROM us_expanders_events WHERE category_id = 4';
        $graphMax = $this->getResultFromCache($graphMaxSql)[0]->max_score;

        $graphDataSql = "SELECT ROUND(MAX(score), 2) as score
                            FROM us_expanders_events 
                            WHERE category_id = 4 
                            AND address_id = $address->id";

        $awardScore = $this->getResultFromCache($graphDataSql)[0]->score;

        $graphData = ['Growth', $awardScore];

        return response()->json([
            'data' => $graphData,
            'graphMax' => $graphMax
        ], 200);
    }


    function findAddressesAndGroupByLatLonTile()
    {
        /**
         * TODO: this is the example of calculating the lat and lon for tile which values is "1000-116":
         * lat = 1000 * 0.05 = 50
         * lon = 116 * 0.106 = 12.29
         */


        $sql = "SELECT a.latlon_tile, a.name, COUNT(a.id) as total_addresses 
                FROM rl_addresses as a
                WHERE a.latlon_tile IS NOT NULL
                AND a.latlon_tile <> '0|0'
                GROUP BY a.latlon_tile";

        $result = $this->DBWithConnection()->select($sql);

        $data = [];

        foreach ($result as $i => $row) {

            $latLonArray = explode('|', $row->latlon_tile);

            $data[$i] = [];

            $data[$i]['name'] = $row->total_addresses > 1 ? $row->total_addresses : $row->name;
            $data[$i]['lat'] = $lat = intval($latLonArray[0]) * 0.05;
            $data[$i]['lon'] = $lon = intval($latLonArray[1]) * 0.106;
            $data[$i]['state_abv'] = $lat.';'.$lon;
            $data[$i]['total_addresses'] = $row->total_addresses;
            $data[$i]['total_addresses_abbrev'] = (string)$this->getAbbreviationValue($row->total_addresses);
        }

        return $data;
    }


    function findCompanyByName()
    {
        $companyName = request()->get('name');

        $sql = "SELECT id, name, address FROM rl_addresses WHERE name LIKE ? LIMIT 50";

        $companies = $this->DBWithConnection()->select($sql, [$companyName.'%']);

        return response()->json($companies);
    }


    function assignAddressToUser()
    {
        $data = request()->validate([
            'userId' => 'required|numeric',
            'entityId' => 'required|numeric',
        ]);

        $address = Address::find($data['entityId']);
        $address->assigned_to = $data['userId'] == -1 ? NULL : $data['userId'];
        $address->save();

        return response()->json($address);
    }


    function loadUsaStates()
    {
        $user = Auth::user();

        if($user->default_country !== 'usa' && $user->default_country !== 'usa-private') {
            return response()->json([], 204);
        }

        $sql = "SELECT id, name, abv FROM rl_usa_state_coords";

        $states = $this->getResultFromCache($sql);

        return response()->json($states, 200);
    }

    public function share(Request $request, LabscapeRocheService $service)
    {
        if(!$service->isUserCanShareAddress(auth()->id())) {
            throw new \Exception('Too many emails sent per day');
        }
        $data = $request->validate([
            'to_name' => 'required|string',
            'address_id' => 'required|exists:rl_addresses,id',
            'to_email' => 'required|email',
            'to_subject' => 'required|string',
            'to_body' => 'required|string',
        ]);

        return $service->shareAddress($data);
    }


    public function setAddressIgnore()
    {
        $data = \request()->validate([
            'reason' => 'required|numeric',
            'id' => 'required|numeric',
        ]);

        $address = Address::where('id', $data['id'])->first();
        $userId = Auth::user()->id;

        if($data['reason'] == 3) {

            $address->customer_status = 1;
            $address->save();

            return response()->json($address);
        }

        $addressIgnore = AddressIgnore::where('user_id', $userId)
            ->where('address_id', $address->id)
            ->where('ignored_type', $data['reason'])
            ->first();

        if(!$addressIgnore) {
            $addressIgnore = AddressIgnore::storeUserIgnore($userId, $address->id, $data['reason']);

            return response()->json($addressIgnore, 200);
        }
        else {
            return response()->json(['message'=>'current ignore already exists'], 409);
        }
    }


    public function removeAddressFromIgnoreList()
    {
        $data = \request()->validate([
            'id' => 'required|numeric'
        ]);

        AddressIgnore::removeAddressFromIgnore($data['id']);

        return response()->json('ok', 200);
    }
}




