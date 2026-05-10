<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;

class MakeRequest extends Command
{
    protected string $name = 'make:request';
    protected string $description = 'Create a new form request class';

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if (!$name) {
            $this->error("Please provide a request name.");
            return 1;
        }

        $path = APP_PATH . '/Http/Requests/' . $name . '.php';
        if (file_exists($path)) {
            $this->error("Request already exists!");
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<EOF
<?php

namespace Anon\Http\Requests;

use Anon\Core\Http\FormRequest;

class {$name} extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // 'title' => 'required|max:255',
        ];
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->success("Form Request [{$name}] created successfully.");
        return 0;
    }
}
