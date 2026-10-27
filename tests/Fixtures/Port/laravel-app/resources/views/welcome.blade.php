@extends('layouts.app')

@section('content')
    <form method="post">@csrf</form>
    @foreach ($users as $user)
        <p>{{ $user->name }} — write to admin@example.com</p>
    @endforeach
    <x-alert type="info" />
    @vite('resources/js/app.js')
@endsection