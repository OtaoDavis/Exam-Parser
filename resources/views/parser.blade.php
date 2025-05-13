<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Parser & Answers</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

</head>

<body class="bg-gray-100 text-gray-800">

    <div class="container mx-auto p-6">
        <h1 class="text-3xl font-bold mb-6 text-center text-gray-700">Exam Parser</h1>
        <div class="bg-white p-6 rounded-lg shadow-md mb-8 max-w-lg mx-auto">
            <h2 class="text-xl font-semibold mb-4">Upload New Exam</h2>
            <form action="{{ route('exams.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="mb-4">
                    <label for="attach" class="block text-sm font-medium text-gray-700 mb-1">Select Exam File</label>
                    <input type="file" id="attach" name="attach"
                           accept=".pdf,.doc,.docx,image/png,image/jpeg" required
                           class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-gray-300 rounded-md cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"/>
                    <p class="mt-1 text-xs text-gray-500">Accepted types: PDF, DOCX, PNG, JPG.</p>
                </div>

                <div class="text-right">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-800 focus:outline-none focus:border-blue-900 focus:ring focus:ring-blue-300 disabled:opacity-25 transition">
                        Upload and Process
                    </button>
                </div>
            </form>
        </div>

        <div class="max-w-4xl mx-auto mb-4">
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif
            @if(session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif
        </div>


        <div class="bg-white p-6 rounded-lg shadow-md max-w-6xl mx-auto">
            <h2 class="text-xl font-semibold mb-4 border-b pb-2">Processed Exams</h2>
        
            @if($exams->isEmpty())
                <p class="text-gray-500 text-center">No exams have been processed yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Question #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Question</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Answer</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($exams as $exam)
                                {{-- Exam header row --}}
                                <tr class="bg-gray-100">
                                    <td colspan="3" class="px-6 py-4 text-sm font-semibold text-gray-800">
                                        {{ $exam->examName ?? 'Unnamed Exam' }} — 
                                        <span class="font-normal text-gray-600">Examiner: {{ $exam->examiner ?? 'N/A' }}</span><br>
                                        <span class="text-xs text-gray-500">
                                            {{ $exam->subject ?? 'N/A' }} • {{ $exam->class ?? 'N/A' }} • 
                                            {{ $exam->curriculum ?? 'N/A' }} • 
                                            Processed at: {{ $exam->created_at->format('Y-m-d H:i') }}
                                        </span>
                                    </td>
                                </tr>
        
                                {{-- Questions under this exam --}}
                                @foreach($exam->questions->sortBy(['question_number', 'question_sub_part']) as $question)
                                    <tr>
                                        <td class="px-6 py-4 text-sm text-gray-700">
                                            Q{{ $question->question_number }}{{ $question->question_sub_part ? '.'.$question->question_sub_part : '' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            {{ $question->question ?? 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700">
                                            {{ $question->answer ?? 'N/A' }}
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
        
                <div class="mt-4">
                    {{ $exams->links() }}
                </div>
            @endif
        </div>
        

    </div>

    <script>
        function toggleAccordion(index) {
            const content = document.getElementById(`accordion-${index}`);
            content.classList.toggle('hidden');
        }
    </script>
    

</body>
</html>