<?php
/**
 * Default homepage class serving as the entry point for public website access.
 * Renders the initial landing page as configured in the framework settings.
 */
class Welcome extends Trongate {

    /**
     * Renders the (default) homepage for public access.
     *
     * @return void
     */
    public function index(): void {
        $data = [
            'view_module' => 'welcome',
            'view_file' => 'default_homepage'
        ];
        $title = "Some title";
        $d_title = "2nd title";
        $logger = new Logger();
        $logger->debug("database query executed");
        $logger->info("user signed in", $title . " " . $d_title);

        $this->templates->public($data);
    }

}
