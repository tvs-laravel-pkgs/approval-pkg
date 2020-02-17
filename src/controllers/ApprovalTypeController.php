<?php

namespace Abs\ApprovalPkg;
use Abs\ApprovalPkg\ApprovalType;
use App\Address;
use App\Country;
use App\Http\Controllers\Controller;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Validator;
use Yajra\Datatables\Datatables;

class ApprovalTypeController extends Controller {

	public function __construct() {
	}

	public function getApprovalTypeList(Request $request) {
		$approval_types = ApprovalType::withTrashed()
			->leftJoin('approval_type_statuses', 'approval_type_statuses.approval_type_id', 'approval_types.id')
			->leftJoin('approval_levels', 'approval_levels.approval_type_id', 'approval_types.id')
			->select(
				'approval_types.id',
				'approval_types.name as approval_type_name',
				'approval_types.code as approval_type_code',
				'approval_types.filter_field',
				DB::raw('count(approval_levels.id) as no_of_levels'),
				DB::raw('count(approval_type_statuses.id) as no_of_status'),
				DB::raw('IF(approval_types.deleted_at IS NULL,"Active","Inactive") as status')
			)
		/*->where('customers.company_id', Auth::user()->company_id)
			->where(function ($query) use ($request) {
				if (!empty($request->customer_code)) {
					$query->where('customers.code', 'LIKE', '%' . $request->customer_code . '%');
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->customer_name)) {
					$query->where('customers.name', 'LIKE', '%' . $request->customer_name . '%');
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->mobile_no)) {
					$query->where('customers.mobile_no', 'LIKE', '%' . $request->mobile_no . '%');
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->email)) {
					$query->where('customers.email', 'LIKE', '%' . $request->email . '%');
				}
			})*/
			->groupBy('approval_types.id')
			->orderby('approval_types.id', 'desc');
		//dd($approval_types);
		return Datatables::of($approval_types)
			->addColumn('name', function ($approval_types) {
				$status = $approval_types->status == 'Active' ? 'green' : 'red';
				return '<span class="status-indicator ' . $status . '"></span>' . $approval_types->approval_type_name;
			})
			->addColumn('action', function ($approval_types) {
				$edit_img = asset('public/theme/img/table/cndn/edit.svg');
				$delete_img = asset('public/theme/img/table/cndn/delete.svg');
				return '
					<a href="#!/approval-pkg/approval-type/edit/' . $approval_types->id . '">
						<img src="' . $edit_img . '" alt="View" class="img-responsive">
					</a>
					<a href="javascript:;" data-toggle="modal" data-target="#delete-approval-type"
					onclick="angular.element(this).scope().deleteApprovalType(' . $approval_types->id . ')" dusk = "delete-btn" title="Delete">
					<img src="' . $delete_img . '" alt="delete" class="img-responsive">
					</a>
					';
			})
			->make(true);
	}

	public function getApprovalTypeFormData(Request $r) {
		$id = $r->id
		if (!$id) {
			$approval_type = new ApprovalType;
			$approval_type->approval_type_statuses = [];
			$action = 'Add';
		} else {
			$approval_type = ApprovalType::withTrashed()->find($id);
			// $address = Address::where('address_of_id', 24)->where('entity_id', $id)->first();
			$action = 'Edit';
		}
		$this->data['country_list'] = $country_list = Collect(Country::select('id', 'name')->get())->prepend(['id' => '', 'name' => 'Select Country']);
		$this->data['approval_type'] = $approval_type;
		// $this->data['address'] = $address;
		$this->data['action'] = $action;

		return response()->json($this->data);
	}

	public function saveApprovalType(Request $request) {
		// dd($request->all());
		try {
			$error_messages = [
				'code.required' => 'Customer Code is Required',
				'code.max' => 'Maximum 255 Characters',
				'code.min' => 'Minimum 3 Characters',
				'name.required' => 'Customer Name is Required',
				'name.max' => 'Maximum 255 Characters',
				'name.min' => 'Minimum 3 Characters',
				'mobile_no.required' => 'Mobile Number is Required',
				'mobile_no.max' => 'Maximum 25 Numbers',
				'email.required' => 'Email is Required',
				'address_line1.required' => 'Address Line 1 is Required',
				'address_line1.max' => 'Maximum 255 Characters',
				'address_line1.min' => 'Minimum 3 Characters',
				'address_line2.max' => 'Maximum 255 Characters',
				'pincode.required' => 'Pincode is Required',
				'pincode.max' => 'Maximum 6 Characters',
				'pincode.min' => 'Minimum 6 Characters',
			];
			$validator = Validator::make($request->all(), [
				'code' => 'required|max:255|min:3',
				'name' => 'required|max:255|min:3',
				'mobile_no' => 'required|max:25',
				'email' => 'required',
				'address_line1' => 'required|max:255|min:3',
				'address_line2' => 'max:255',
				'pincode' => 'required|max:6|min:6',
			], $error_messages);
			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			DB::beginTransaction();
			if (!$request->id) {
				$customer = new ApprovalType;
				$customer->created_by_id = Auth::user()->id;
				$customer->created_at = Carbon::now();
				$customer->updated_at = NULL;
				$address = new Address;
			} else {
				$customer = ApprovalType::withTrashed()->find($request->id);
				$customer->updated_by_id = Auth::user()->id;
				$customer->updated_at = Carbon::now();
				$address = Address::where('address_of_id', 24)->where('entity_id', $request->id)->first();
			}
			$customer->fill($request->all());
			$customer->company_id = Auth::user()->company_id;
			if ($request->status == 'Inactive') {
				$customer->deleted_at = Carbon::now();
				$customer->deleted_by_id = Auth::user()->id;
			} else {
				$customer->deleted_by_id = NULL;
				$customer->deleted_at = NULL;
			}
			$customer->save();

			$address->fill($request->all());
			$address->company_id = Auth::user()->company_id;
			$address->address_of_id = 24;
			$address->entity_id = $customer->id;
			$address->address_type_id = 40;
			$address->name = 'Primary Address';
			$address->save();

			DB::commit();
			if (!($request->id)) {
				return response()->json(['success' => true, 'message' => ['Customer Details Added Successfully']]);
			} else {
				return response()->json(['success' => true, 'message' => ['Customer Details Updated Successfully']]);
			}
		} catch (Exceprion $e) {
			DB::rollBack();
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}
	public function deleteApprovalType($id) {
		$delete_status = ApprovalType::withTrashed()->where('id', $id)->forceDelete();
		if ($delete_status) {
			$address_delete = Address::where('address_of_id', 24)->where('entity_id', $id)->forceDelete();
			return response()->json(['success' => true]);
		}
	}
}