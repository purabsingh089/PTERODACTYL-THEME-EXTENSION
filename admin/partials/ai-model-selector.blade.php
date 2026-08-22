{{--
  Reference partial — AI model selector: pick which monkeycode-ai.net model
  powers each feature (DeepSeek V4 Flash for fast diagnostics; Qwen 3.5 Plus /
  Qwen 3.8 for deeper analysis).

  NOTE: Blueprint installs ONLY the single file bound to admin.view, so this
  section is inlined in admin/view.blade.php behind "PARTIAL: ai-model-selector".
  Kept for spec-structure parity.

  Data it renders: $models (MonkeyCodeClient::MODELS) and
  $settings['ai']['models'][fix|optimize].
--}}

<div class="prx-field">
    <label>AI Fixer model (fast diagnostics)</label>
    <select id="prx-model-fix" class="prx-input">
        @foreach ($models as $modelId => $label)
            <option value="{{ $modelId }}"
                {{ ($settings['ai']['models']['fix'] ?? 'deepseek-v4-flash') === $modelId ? 'selected' : '' }}>
                {{ $label }}
            </option>
        @endforeach
    </select>
</div>
<div class="prx-field">
    <label>AI Optimizer model (deep analysis)</label>
    <select id="prx-model-optimize" class="prx-input">
        @foreach ($models as $modelId => $label)
            <option value="{{ $modelId }}"
                {{ ($settings['ai']['models']['optimize'] ?? 'qwen3.5-plus') === $modelId ? 'selected' : '' }}>
                {{ $label }}
            </option>
        @endforeach
    </select>
</div>
