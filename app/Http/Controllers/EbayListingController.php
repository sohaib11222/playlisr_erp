<?php

namespace App\Http\Controllers;

use App\Services\Ebay\EbayListingService;
use Illuminate\Http\Request;

class EbayListingController extends Controller
{
    protected function guardListing(Request $request)
    {
        $user = auth()->user();
        if (!$user || (!$user->can('product.update') && !$user->can('superadmin'))) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected function businessId(Request $request)
    {
        return (int) $request->session()->get('user.business_id');
    }

    public function list(Request $request, $id)
    {
        $this->guardListing($request);
        $service = new EbayListingService($this->businessId($request));
        $result = $service->listProduct((int) $id);
        return response()->json($result);
    }

    public function bulkList(Request $request)
    {
        $this->guardListing($request);
        $service = new EbayListingService($this->businessId($request));
        $result = $service->bulkList($request->input('product_ids', []));
        return response()->json($result);
    }

    public function preflight(Request $request, $id)
    {
        $this->guardListing($request);
        $service = new EbayListingService($this->businessId($request));
        return response()->json($service->preflight((int) $id));
    }

    public function readiness(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            abort(403, 'Unauthorized action.');
        }
        $service = new EbayListingService($this->businessId($request));
        return response()->json($service->readiness());
    }
}
