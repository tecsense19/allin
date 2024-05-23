<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function index(){
        return view('Admin.User.list');
    }
    public function indexPost(Request $request)
    {
        $query = User::where('role','User');
        $totalCount = $query->count();
        if ($request->has('order')) {
            foreach ($request->order as $order) {
                $column = $request->columns[$order['column']]['data'];
                $dir = $order['dir'];
                //$query->orderBy($column, $dir);
                $query->orderBy('id', 'desc');
            }
        }else{
            $query->orderBy('id', 'desc');
        }
        if ($request->has('search') && !is_null($request->search['value'])) {
            $search = $request->search['value'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                  ->orWhere('last_name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('mobile', 'LIKE', "%{$search}%");
            });
        }
        $filterCount = $query->count();
        return DataTables::of($query)
            ->editColumn('account_id', function ($user) {
                return $user->account_id ?? '-';
            })
            ->editColumn('first_name', function ($user) {
                $first_name = $user->first_name ? $user->first_name : '';
                $last_name = $user->last_name ? $user->last_name : '';
                return $first_name.' '.$last_name   ;
            })
            ->editColumn('email', function ($user) {
                return $user->email ?? '-';
            })
            ->editColumn('mobile', function ($user) {
                return $user->mobile ?? '-';
            })
            ->editColumn('profile', function ($user) {
                $userImage = URL::to('public/assets/media/avatars/blank.png');
                if(!empty($user->profile)){
                    $userImage = URL::to('public/user-profile/'.$user->profile);
                }
                $html =  '<img class="img rounded-circle" height="65" width="65" src="'.$userImage.'" />';
                return $html;
            })
            ->editColumn('action', function ($user) {
                $html =  '<div class="row"><div class="col-12"><a href="#" class="" title="Edit"><i class="fas fa-pen"></i></a> <a href="#" class="mx-md-3" title="View"><i class="fas fa-eye"></i></a> <a href="#" class="" title="Delete"><i class="fas fa-trash"></i></a></div></div>';
                return $html;
            })
            ->rawColumns(['profile','cover_image', 'action'])
            ->with('recordsTotal', $totalCount)
            ->with('recordsFiltered', $filterCount)
            ->make(true);
    }
}