<?php
class Demo extends Trongate {

    public function index(): void {
        Inertia::render('Demo/Index', [
            'records' => $this->db->get('id', 'demo_table', 'ASC', 'array'),
        ]);
    }

    public function about(): void {
        Inertia::render('Demo/About', [
            'records' => $this->db->get('id', 'demo_table', 'ASC', 'array'),
        ]);
    }
    public function demo(): void {
        Inertia::render('Demo/Demo');
    }

    public function create(): void {
        $this->validation->set_rules('name', 'name', 'required|min_length[3]');

        if ($this->validation->run() !== true) {   // also checks CSRF
            Inertia::back();                        // errors arrive as props.errors
        }

        $this->db->insert(['name' => post('name', true)], 'demo_table');
        set_flashdata('Record added');              // arrives as props.flash.message
        Inertia::redirect('demo');
    }

    public function delete(): void {
        $this->validation->run();                   // CSRF gate
        $this->db->delete(segment(3, 'int'), 'demo_table');
        Inertia::redirect('demo');
    }
}