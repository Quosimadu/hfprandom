<h1>{{ trans('csv.text_headline')}}</h1>
<p>{{ trans('csv.text_description')}}</p>
{!! Form::open(['route' => 'csvReformat', 'files' => true]) !!}
    <p>
    {{ Form::label(trans('csv.label_account')) }}<br />
    {!! Form::select('account', $mainAccounts) !!}<br />
    </p>

    <p>
    {{ Form::label(trans('csv.label_csv_file')) }}<br />
    {!! Form::file('csv') !!}
    </p>

    <p>
    {!! Form::submit(trans('csv.button_submit')) !!}
    </p>
{!! Form::close() !!}

