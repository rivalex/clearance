@props(['columns' => 5, 'rows' => 5])

<div {{ $attributes }}>
    <flux:skeleton.group animate="pulse">
        <flux:table>
            <flux:table.columns>
                @for($i = 0; $i < $columns; $i++)
                    <flux:table.column>
                        <flux:skeleton class="h-3 w-16" />
                    </flux:table.column>
                @endfor
            </flux:table.columns>

            <flux:table.rows>
                @for($j = 0; $j < $rows; $j++)
                    <flux:table.row>
                        @for($i = 0; $i < $columns; $i++)
                            <flux:table.cell>
                                <flux:skeleton.line />
                            </flux:table.cell>
                        @endfor
                    </flux:table.row>
                @endfor
            </flux:table.rows>
        </flux:table>
    </flux:skeleton.group>
</div>
