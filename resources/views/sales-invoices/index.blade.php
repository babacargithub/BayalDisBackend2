@extends('layouts.app')

@section('title', 'Dettes clients')

@section('header')
    Dettes clients
@endsection

@section('content')
    <livewire:sales-invoices.debt-list />
@endsection
