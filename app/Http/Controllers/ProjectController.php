<?php

namespace App\Http\Controllers;

use App\Project;
use App\User;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $status = $request->input('status');

        $query = Project::with(['creator', 'startedBy', 'completedBy', 'contributors'])
            ->where('business_id', $business_id);

        if (!empty($status)) {
            $query->where('status', $status);
        }

        $projects = $query->orderByDesc('created_at')->paginate(50)->appends($request->except('page'));

        return view('projects.index', compact('projects', 'status'));
    }

    public function create(Request $request)
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
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
        return view('projects.edit', compact('project'));
    }

    public function update($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $project = Project::where('business_id', $business_id)->findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'status' => 'required|in:not_started,in_progress,complete',
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
