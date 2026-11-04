@extends('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.layout')

@section('inhoud')
    <h1>Rendabiliteit {{ $periode }}</h1>
    <p class="ondertitel">Eén maand in detail · alle bedragen excl. btw</p>

    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.waarschuwingen')
    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.kerncijfers')
    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.maand-detail')
    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.uitleg')
@endsection
