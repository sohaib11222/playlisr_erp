<?php

namespace App\Http\Controllers;

use App\Project;
use App\User;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    // Same store keys/labels as StoreTaskController (employee_tasks board) /
    // TaskController (weekly tasks).
    const STORE_LABELS = [
        'pico'      => 'Pico',
        'hollywood' => 'Hollywood',
    ];

    const PRIORITY_LABELS = [
        'high'   => 'High',
        'medium' => 'Medium',
        'low'    => 'Low',
    ];

    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function isAdmin()
    {
        return $this->businessUtil->is_admin(auth()->user());
    }

    /** See TaskController::availableStores for the same convention. */
    private function availableStores()
    {
        if ($this->isAdmin()) {
            return self::STORE_LABELS;
        }
        return OpeningChecklistController::storesForUser();
    }

    /** See TaskController::resolveStore for the same convention. */
    private function resolveStore(Request $request, array $availableStores)
    {
        $requested = $request->input('store');

        if ($this->isAdmin()) {
            return (!empty($requested) && isset(self::STORE_LABELS[$requested])) ? $requested : null;
        }

        if (!empty($requested) && isset($availableStores[$requested])) {
            return $requested;
        }

        return array_key_first($availableStores) ?: OpeningChecklistController::defaultStoreForUser();
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $status = $request->input('status');
        $priority = $request->input('priority');
        $storeLabels = $this->availableStores();
        $store = $this->resolveStore($request, $storeLabels);

        $query = Project::with(['creator', 'startedBy', 'completedBy', 'contributors'])
            ->where('business_id', $business_id);

        if (!empty($status)) {
            $query->where('status', $status);
        }
        if (!empty($priority)) {
            $query->where('priority', $priority);
        }
        if (!empty($store)) {
            $query->where(function ($q) use ($store) {
                $q->where('store', $store)->orWhereNull('store');
            });
        }

        $projects = $query->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->paginate(50)->appends($request->except('page'));
        $priorityLabels = self::PRIORITY_LABELS;
        $canToggleStore = $this->isAdmin();

        return view('projects.index', compact('projects', 'status', 'priority', 'store', 'storeLabels', 'priorityLabels', 'canToggleStore'));
    }

    public function create(Request $request)
    {
        $storeLabels = $this->availableStores();
        $priorityLabels = self::PRIORITY_LABELS;
        return view('projects.create', compact('storeLabels', 'priorityLabels'));
    }

    public function store(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'store' => 'nullable|in:' . implode(',', array_keys($this->availableStores())),
            'priority' => 'required|in:' . implode(',', array_keys(self::PRIORITY_LABELS)),
        ]);

        $data['business_id'] = $business_id;
        $data['created_by'] = auth()->id();
        $data['status'] = 'not_started';

        $project = Project::create($data);
        // The person who starts a project is naturally the first one credited
        // with joining it.
        $project->contributors()->attach(auth()->id(), ['joined_at' => now()]);

        return redirect(action('ProjectController@index'))
            ->with('status', ['success' => true, 'msg' => 'Project added.']);
    }

    public function edit($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::with('contributors')->where('business_id', $business_id)->findOrFail($id);
        $storeLabels = $this->availableStores();
        $priorityLabels = self::PRIORITY_LABELS;
        return view('projects.edit', compact('project', 'storeLabels', 'priorityLabels'));
    }

    public function update($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::where('business_id', $business_id)->findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'status' => 'required|in:not_started,in_progress,complete',
            'store' => 'nullable|in:' . implode(',', array_keys($this->availableStores())),
            'priority' => 'required|in:' . implode(',', array_keys(self::PRIORITY_LABELS)),
        ]);

        $this->applyStatusTransition($project, $data['status']);
        unset($data['status']);
        $project->fill($data)->save();

        return redirect(action('ProjectController@index'))
            ->with('status', ['success' => true, 'msg' => 'Project updated.']);
    }

    /** Quick status change from the dropdown on the list page. */
    public function updateStatus($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::where('business_id', $business_id)->findOrFail($id);

        $newStatus = $request->validate([
            'status' => 'required|in:not_started,in_progress,complete',
        ])['status'];

        $this->applyStatusTransition($project, $newStatus);
        $project->save();

        return redirect(action('ProjectController@index'))
            ->with('status', ['success' => true, 'msg' => 'Status updated.']);
    }

    /** Anyone can credit themselves as having joined in on a project. */
    public function join($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::where('business_id', $business_id)->findOrFail($id);

        if (!$project->contributors()->where('user_id', auth()->id())->exists()) {
            $project->contributors()->attach(auth()->id(), ['joined_at' => now()]);
        }

        return redirect(action('ProjectController@index'))
            ->with('status', ['success' => true, 'msg' => 'You joined this project.']);
    }

    public function removeContributor($id, $userId, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::where('business_id', $business_id)->findOrFail($id);

        if ((int) $userId !== (int) auth()->id() && !$this->businessUtil->is_admin(auth()->user())) {
            abort(403);
        }

        $project->contributors()->detach($userId);

        return redirect(action('ProjectController@index'))
            ->with('status', ['success' => true, 'msg' => 'Contributor removed.']);
    }

    public function destroy($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::where('business_id', $business_id)->findOrFail($id);
        $project->delete();

        return redirect(action('ProjectController@index'))
            ->with('status', ['success' => true, 'msg' => 'Project deleted.']);
    }

    /**
     * Sets status plus who-started/who-completed attribution based on the
     * transition being made. See TaskController::applyStatusTransition for
     * the same convention on weekly tasks.
     */
    private function applyStatusTransition(Project $project, string $newStatus)
    {
        $oldStatus = $project->status;

        if ($newStatus === 'in_progress' && $oldStatus !== 'in_progress') {
            $project->started_by = auth()->id();
            $project->started_at = now();
        }
        if ($newStatus === 'complete' && $oldStatus !== 'complete') {
            $project->completed_by = auth()->id();
            $project->completed_at = now();
        }
        if ($newStatus !== 'complete' && $oldStatus === 'complete') {
            $project->completed_by = null;
            $project->completed_at = null;
        }
        if ($newStatus === 'not_started' && $oldStatus !== 'not_started') {
            $project->started_by = null;
            $project->started_at = null;
        }

        $project->status = $newStatus;
    }
}
