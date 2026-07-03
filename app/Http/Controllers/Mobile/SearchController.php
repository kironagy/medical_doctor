<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function search(Request $request)
    {
        $query = $request->get('q');

        if (!$query || strlen($query) < 2) {
            return view('mobile.search', ['results' => [], 'query' => $query, 'pageTitle' => 'Search']);
        }

        try {
            $data = $this->api->get('/search', ['q' => $query]);
            return view('mobile.search', [
                'results' => $data['results'] ?? [],
                'query' => $query,
                'pageTitle' => 'Search',
            ]);
        } catch (\Exception $e) {
            return view('mobile.search', [
                'results' => [],
                'query' => $query,
                'error' => $e->getMessage(),
                'pageTitle' => 'Search',
            ]);
        }
    }
}
