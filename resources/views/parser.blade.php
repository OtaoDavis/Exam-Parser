<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Parser</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <form action="{{ route('exams.store') }}" method="POST" enctype="multipart/form-data">
        @csrf <label for="attach">Accepted file types (.pdf, .docx, .jpg, .png, .zip, .rar)</label> <br>
        <input type="file" id="attach" name="attach" accept=".pdf,.doc,.docx,image/png,image/jpeg, .zip, .rar" required />
        <br><br>
        <button type="submit">Upload and Parse</button>
    </form>
    
    @if(session('success'))
        <div style="color: green;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="color: red;">{{ session('error') }}</div>
    @endif

</body>

</html>