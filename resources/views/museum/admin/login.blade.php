@extends('museum.layout')
@section('title', 'ورود کارکنان')
@section('content')
<form method="post" action="{{ route('museum.admin.login.post') }}" class="card" style="max-width:420px;margin:auto">
  @csrf
  <h1>ورود کارکنان موزه</h1>
  @error('email')<div class="notice err">{{ $message }}</div>@enderror
  <label for="em">ایمیل</label><input id="em" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
  <label for="pw">رمز عبور</label><input id="pw" type="password" name="password" required autocomplete="current-password">
  <label class="check"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label>
  <p><button class="btn">ورود</button></p>
</form>
@endsection
