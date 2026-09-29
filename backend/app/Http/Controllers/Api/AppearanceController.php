<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use App\Support\Appearance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Self-scoped personal preferences - every authenticated user manages
 * their own Appearance settings, at every territory tier, with no
 * module/permission gate. See docs/specs/appearance-settings-spec.md.
 */
class AppearanceController extends Controller
{
    public function show(Request $request)
    {
        return $this->respond($request->user()->id);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), Appearance::validationRules());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user()->id;

        foreach (Appearance::OPTIONS as $key => $option) {
            if (! $request->has($key)) {
                continue;
            }

            $value = $request->input($key);

            // A value equal to its default doesn't get a stored row -
            // and clears any existing one, so toggling back to default
            // actually resets rather than just storing the default
            // explicitly (see Appearance::isDefault()'s docblock).
            if (Appearance::isDefault($key, $value)) {
                UserPreference::where('user_id', $userId)->where('key', $key)->delete();

                continue;
            }

            $stored = ! empty($option['boolean'])
                ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : $value;

            UserPreference::updateOrCreate(
                ['user_id' => $userId, 'key' => $key],
                ['value' => $stored]
            );
        }

        return $this->respond($userId);
    }

    public function destroy(Request $request)
    {
        UserPreference::where('user_id', $request->user()->id)->delete();

        return $this->respond($request->user()->id);
    }

    private function respond(int $userId)
    {
        $savedRows = UserPreference::where('user_id', $userId)->pluck('value', 'key')->all();
        $effective = Appearance::effectiveFor($savedRows);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Appearance settings retrieved successfully',
            'data' => array_merge($effective, [
                'classes' => Appearance::classesFor($effective),
                'accent_rgb' => Appearance::ACCENT_RGB[$effective['accent']],
            ]),
        ]);
    }
}
