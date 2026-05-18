<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\GuardService;

uses(Tests\TestCase::class);

it('reproduces the issue where adding a permission fails if it exists on another guard', function () {
    // Setup: same prefix, different guards
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);
    
    // We have an existing group 'orders' on 'api' with only 'read'
    Permission::create(['name' => 'orders-read', 'guard_name' => 'api']);
    
    // Test: edit 'orders' on 'api' and try to add 'create'
    // Note: PermissionForm needs to know we are editing 'orders' on 'api'
    // But wait, the component might need the guard to be set properly.
    
    $component = Livewire::test(PermissionForm::class, ['editingPrefix' => 'orders'])
        ->set('guardName', 'api');
        
    // Debug: check current crudAbilities
    // If mount bug is there, it might be empty
    
    $component->set('crudAbilities', ['read', 'create'])
        ->call('save');
        
    $exists = Permission::where('name', 'orders-create')
        ->where('guard_name', 'api')
        ->exists();
        
    expect($exists)->toBeTrue();
});
