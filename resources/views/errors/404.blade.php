@extends('layouts.public')

@section('content')
    <div class="container-app flex min-h-[60vh] flex-col items-center justify-center py-16 text-center">
        <p class="gradient-text text-7xl font-extrabold sm:text-8xl">{{ $code ?? 404 }}</p>
        <h1 class="mt-4 text-2xl font-bold text-slate-900 sm:text-3xl">
            {{ ($code ?? 404) === 404 ? 'Page not found' : (($code ?? 0) === 429 ? 'Too many requests' : 'Something went wrong') }}
        </h1>
        <p class="mt-3 max-w-md text-slate-600">
            {{ ($code ?? 404) === 404
                ? 'The page you are looking for does not exist or may have been moved. Try one of our tools instead.'
                : 'Please wait a moment and try again.' }}
        </p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('home') }}" class="btn-accent">Back to Home</a>
            <a href="{{ route('tools.index') }}" class="btn-secondary">Browse Tools</a>
        </div>
    </div>
@endsection
