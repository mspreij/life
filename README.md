Run: `./clife`  
or   `./wireworld` which is a symlink to clife

3 basic modes are paused, running and drawing.

**Shortcuts:**  
cursor keys move pointer and wrap sides, pageup/pagedown move it faster but don't go past border.  
`space` toggles cell active/dormant (or alive/dead)  
`enter` start/stop running  
`d`raw to toggle drawing mode, moving cursor will draw cells  
`s`tep: advance one turn  
`r`andom fills the middle quarter of the field with 1/3rd random live cells  
`c`lear the field  
`-/+` running slower/faster  

For wireworld `b`rush changes the state/color for draw, and for space although it's not shown when not in drawing mode (probably also for clife?)  
`c`lear will turn any non-empty cell to wire instead of removing everything.
