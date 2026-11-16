@extends('layouts.public')

@section('content')
    <div class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs ?? []])

        <div class="grid gap-10 lg:grid-cols-2">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Contact Us</h1>
                <p class="mt-4 text-lg leading-relaxed text-slate-600">
                    Questions, feedback, bug reports or partnership inquiries — we would love to hear from you.
                    Fill in the form and we will reply as soon as possible.
                </p>

                <div class="mt-8 space-y-4">
                    @if($email = \App\Models\SiteSetting::get('contact_email'))
                        <div class="card flex items-center gap-3 p-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600/10 text-brand-600">
                                <x-icon name="doc" class="h-5 w-5"/>
                            </span>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Email</p>
                                <p class="text-sm text-slate-600">{{ $email }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="card flex items-center gap-3 p-4">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600/10 text-brand-600">
                            <x-icon name="clock" class="h-5 w-5"/>
                        </span>
                        <div>
                            <p class="text-sm font-medium text-slate-900">Response time</p>
                            <p class="text-sm text-slate-600">Usually within 1–2 business days</p>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('contact.submit') }}" class="card p-6 sm:p-8" x-data="{ submitted: false }">
                @csrf
                <input type="text" name="website" value="" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

                <div class="space-y-5">
                    <div>
                        <label for="name" class="label">Your name</label>
                        <input id="name" name="name" type="text" required maxlength="100" class="input" placeholder="Jane Doe" value="{{ old('name') }}">
                    </div>
                    <div>
                        <label for="email" class="label">Email address</label>
                        <input id="email" name="email" type="email" required maxlength="150" class="input" placeholder="jane@example.com" value="{{ old('email') }}">
                    </div>
                    <div>
                        <label for="subject" class="label">Subject</label>
                        <input id="subject" name="subject" type="text" required maxlength="200" class="input" placeholder="How can we help?" value="{{ old('subject') }}">
                    </div>
                    <div>
                        <label for="message" class="label">Message</label>
                        <textarea id="message" name="message" required maxlength="5000" rows="6" class="input resize-y" placeholder="Write your message…">{{ old('message') }}</textarea>
                    </div>
                    <button type="submit" class="btn-accent btn-lg w-full">
                        Send message
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
