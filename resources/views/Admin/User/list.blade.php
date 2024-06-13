@extends('Admin.layouts.app')

@section('title', 'AllIn | User List')
@section('header')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.6/css/jquery.dataTables.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@endsection

@section('content')
    <div class="d-flex flex-column flex-column-fluid">
        <!--begin::Toolbar-->
        <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
            <!--begin::Toolbar container-->
            <div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
                <!--begin::Page title-->
                <div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
                    <!--begin::Title-->
                    <h1 class="page-heading d-flex text-dark fw-bold fs-3 flex-column justify-content-center my-0">Users
                        List
                    </h1>
                    <!--end::Title-->
                    <!--begin::Breadcrumb-->
                    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
                        <!--begin::Item-->
                        <li class="breadcrumb-item text-muted">
                            <a href="{{ route('dashboard') }}" class="text-muted text-hover-primary">Dashboard</a>
                        </li>
                        <!--end::Item-->
                        <!--begin::Item-->
                        <li class="breadcrumb-item">
                            <span class="bullet bg-gray-400 w-5px h-2px"></span>
                        </li>
                        <!--end::Item-->
                        <!--begin::Item-->
                        <li class="breadcrumb-item text-muted">User List</li>
                        <!--end::Item-->
                    </ul>
                    <!--end::Breadcrumb-->
                </div>

            </div>
        </div>

        <div id="kt_app_content" class="app-content flex-column-fluid">
            <!--begin::Content container-->
            <div id="kt_app_content_container" class="app-container container-xxl">
                <!--begin::Card-->
                <div class="card">
                    <!--begin::Card body-->
                    <div class="card-body py-4">
                        <!--begin::Table-->
                        <div class="py-5">
                            <div class="mb-3 row justify-content-end">
                                <div class="col-3">
                                    <input type="text" id="global-search" class="form-control" placeholder="Search..">
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-row-bordered table-row-gray-300 gy-7" id="datatable">
                                    <thead>
                                        <tr class="fw-bold fs-6 text-gray-800">
                                            <th>Profile</th>
                                            <th>Account Number</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Mobile</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                        <!--end::Table-->
                    </div>
                    <!--end::Card body-->
                </div>
                <!--end::Card-->
            </div>
            <!--end::Content container-->
        </div>
    </div>
@endsection

@section('script')
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.6/js/jquery.dataTables.js"></script>
    <script>
        $(document).ready(function() {
            var table = $('#datatable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('userListPost') }}',
                    type: 'POST',
                    data: function(d) {
                        d._token = '{{ csrf_token() }}';
                    }
                },
                columns: [{
                        data: 'profile',
                        name: 'profile',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'account_id',
                        name: 'account_id',
                        orderable: true,
                        searchable: true
                    },
                    {
                        data: 'first_name',
                        name: 'first_name',
                        orderable: true,
                        searchable: true
                    },
                    {
                        data: 'email',
                        name: 'email',
                        orderable: true,
                        searchable: true
                    },
                    {
                        data: 'mobile',
                        name: 'mobile',
                        orderable: true,
                        searchable: true
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false
                    }
                ],
                order: [],
                pageLength: 10,
                autoWidth: true
            });
            $('#global-search').on('keyup', function() {
                table.search(this.value).draw();
            });
        });

        function deleteUser(id) {
            // Show SweetAlert confirmation popup
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // If user confirms, send AJAX request to delete user
                    try {
                        $.ajax({
                            url: '{{ route('delete_user') }}',
                            type: 'POST',
                            data: {
                                id: id,
                                _token: '{{ csrf_token() }}'
                            },
                            success: function(response) {
                                try {
                                    if (response.success) {
                                        Swal.fire({
                                            title: 'Deleted!',
                                            text: 'The user has been deleted.',
                                            icon: 'success',
                                            confirmButtonText: 'OK'
                                        }).then((result) => {
                                            if (result.isConfirmed) {
                                                location.reload();
                                            }
                                        });

                                    } else {
                                        Swal.fire(
                                            'Error!',
                                            response.message ||
                                            'An error occurred while deleting the user.',
                                            'error'
                                        );
                                    }
                                } catch (e) {
                                    Swal.fire(
                                        'Error!',
                                        'An unexpected error occurred.',
                                        'error'
                                    );
                                    console.error('Error in success callback:', e);
                                }
                            },
                            error: function(xhr, status, error) {
                                try {
                                    Swal.fire(
                                        'Error!',
                                        'An error occurred while deleting the user.',
                                        'error'
                                    );
                                    console.error('AJAX error:', error);
                                } catch (e) {
                                    Swal.fire(
                                        'Error!',
                                        'An unexpected error occurred.',
                                        'error'
                                    );
                                    console.error('Error in error callback:', e);
                                }
                            }
                        });
                    } catch (e) {
                        Swal.fire(
                            'Error!',
                            'An unexpected error occurred while sending the request.',
                            'error'
                        );
                        console.error('Error in AJAX request:', e);
                    }
                }
            });
        }
    </script>
@endsection
