@extends('Admin.layouts.app')

@section('title', 'AllIn | User Details')
@section('header')
    <style>
        .container-xxl {
            max-width: 100% !important;
        }
    </style>
@endsection

@section('content')

    <div class="col-12">
        <div class="d-flex flex-column">
            <!--begin::Toolbar-->
            <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
                <!--begin::Toolbar container-->
                <div id="kt_app_content_container" class="app-container container-xxl">
                    <div class="card mb-5 mb-xxl-8">
                        <div class="card-body pt-9 pb-0">
                            <!--begin::Details-->
                            <div class="d-flex flex-wrap flex-sm-nowrap">
                                <!--begin: Pic-->
                                <div class="me-7 mb-4">
                                    <div class="symbol symbol-100px symbol-lg-160px symbol-fixed position-relative">
                                        <img src="{{ $user->profile }}" alt="image">
                                        <div
                                            class="position-absolute translate-middle bottom-0 start-100 mb-6 bg-success rounded-circle border border-4 border-body h-20px w-20px">
                                        </div>
                                    </div>
                                </div>
                                <!--end::Pic-->
                                <!--begin::Info-->
                                <div class="flex-grow-1">
                                    <!--begin::Title-->
                                    <div class="d-flex justify-content-between align-items-start flex-wrap mb-2">
                                        <!--begin::User-->
                                        <div class="d-flex flex-column">
                                            <!--begin::Name-->
                                            <div class="d-flex align-items-center mb-2">
                                                <a href="#"
                                                    class="text-gray-900 text-hover-primary fs-2 fw-bold me-1">{{ $user->first_name }}
                                                    {{ $user->last_name }}</a>
                                                <a href="#">
                                                    <i class="ki-duotone ki-verify fs-1 text-primary">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                </a>
                                            </div>
                                            <!--end::Name-->
                                            <!--begin::Info-->
                                            <div class="d-flex flex-wrap fw-semibold fs-6 mb-4 pe-2">
                                                <a href="#"
                                                    class="d-flex align-items-center text-gray-400 text-hover-primary me-5 mb-2">
                                                    <i class="ki-duotone ki-profile-circle fs-4 me-1">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                        <span class="path3"></span>
                                                    </i>{{ $user->account_id }}</a>
                                                <a href="#"
                                                    class="d-flex align-items-center text-gray-400 text-hover-primary me-5 mb-2">
                                                    <i class="fas fa-phone fs-4 me-1">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>{{ $user->country_code }} {{ $user->mobile }}</a>
                                                <a href="#"
                                                    class="d-flex align-items-center text-gray-400 text-hover-primary mb-2">
                                                    <i class="ki-duotone ki-sms fs-4 me-1">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>{{ $user->email }}</a>
                                            </div>
                                            <!--end::Info-->
                                        </div>
                                        <!--end::User-->
                                    </div>
                                    <!--end::Title-->
                                </div>
                                <!--end::Info-->
                            </div>
                            <!--end::Details-->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex">
        <div class="col-md-6">
            <div class="d-flex flex-column">
                <!--begin::Toolbar-->
                <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
                    <!--begin::Toolbar container-->
                    <div id="kt_app_content_container" class="app-container container-xxl">
                        <div class="card mb-5 mb-xxl-8">
                            <div class="card-body pt-9 pb-0">
                                <!--begin::Details-->
                                <div class="d-flex flex-wrap flex-sm-nowrap">
                                    <!--begin: Pic-->
                                    <div class="me-7 mb-4">
                                        <div class="symbol symbol-100px symbol-lg-160px symbol-fixed position-relative">
                                            <img src="{{ $user->cover_image }}" alt="image">
                                        </div>
                                    </div>
                                    <!--end::Pic-->
                                    <!--begin::Info-->
                                    <div class="flex-grow-1">
                                        <span>Description</span>
                                        <p><strong>{{ $user->description }}</strong></p>
                                    </div>
                                    <!--end::Info-->
                                </div>
                                <!--end::Details-->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="d-flex flex-column">
                <!--begin::Toolbar-->
                <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
                    <!--begin::Toolbar container-->
                    <div id="kt_app_content_container" class="app-container container-xxl">
                        <div class="card mb-5 mb-xxl-8">
                            <div class="card-body pt-9 pb-9">
                                <!--begin::Details-->
                                <div class="row align-items-center my-3">
                                    <div class="col-auto">
                                        <div class="symbol symbol-45px w-45px bg-light me-5">
                                            <img src="{{ URL::to('public/assets/media/svg/brand-logos/instagram-2-1.svg') }}"
                                                alt="image" class="p-3">
                                        </div>
                                    </div>
                                    <div class="col">{{$user->instagram_profile_url}}</div>
                                </div>
                                <div class="row align-items-center my-3">
                                    <div class="col-auto">
                                        <div class="symbol symbol-45px w-45px bg-light me-5">
                                            <img src="{{ URL::to('public/assets/media/svg/brand-logos/facebook-2.svg') }}"
                                                alt="image" class="p-3">
                                        </div>
                                    </div>
                                    <div class="col">{{$user->facebook_profile_url}}</div>
                                </div>
                                <div class="row align-items-center my-3">
                                    <div class="col-auto">
                                        <div class="symbol symbol-45px w-45px bg-light me-5">
                                            <img src="{{ URL::to('public/assets/media/svg/brand-logos/twitter.svg') }}"
                                                alt="image" class="p-3">
                                        </div>
                                    </div>
                                    <div class="col">{{$user->twitter_profile_url}}</div>
                                </div>
                                <div class="row align-items-center my-3">
                                    <div class="col-auto">
                                        <div class="symbol symbol-45px w-45px bg-light me-5">
                                            <img src="{{ URL::to('public/assets/media/svg/brand-logos/youtube.svg') }}"
                                                alt="image" class="p-3">
                                        </div>
                                    </div>
                                    <div class="col">{{$user->youtube_profile_url}}</div>
                                </div>
                                <div class="row align-items-center my-3">
                                    <div class="col-auto">
                                        <div class="symbol symbol-45px w-45px bg-light me-5">
                                            <img src="{{ URL::to('public/assets/media/svg/brand-logos/linkedin-1.svg') }}"
                                                alt="image" class="p-3">
                                        </div>
                                    </div>
                                    <div class="col">{{$user->linkedin_profile_url}}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



@endsection

@section('script')
@endsection
