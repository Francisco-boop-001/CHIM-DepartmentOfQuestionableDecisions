Scriptname WorldOfDramaLlamaMCM extends SKI_ConfigBase

Int _openKeyCode = -1
Int _openKeyOption = -1

Int Function GetVersion()
	Return 1
EndFunction

Event OnConfigInit()
	ModName = "World of Drama Llama: The Department of Questionable Decisions"
	Pages = new String[1]
	Pages[0] = "General"
	_openKeyCode = WorldOfDramaLlamaNative.GetOpenKeyCode()
EndEvent

Event OnConfigOpen()
	_openKeyCode = WorldOfDramaLlamaNative.GetOpenKeyCode()
EndEvent

Event OnPageReset(String a_page)
	If a_page == "" || a_page == "General"
		SetTitleText("World of Drama Llama")
		SetCursorFillMode(TOP_TO_BOTTOM)
		AddHeaderOption("Interface")
		_openKeyOption = AddKeyMapOption("Open department", _openKeyCode, OPTION_FLAG_WITH_UNMAP)
		AddTextOption("Supported input", "Keyboard and gamepad", OPTION_FLAG_DISABLED)
		AddTextOption("Open-key binding", "Mouse buttons and wheel are unsupported", OPTION_FLAG_DISABLED)
	EndIf
EndEvent

Event OnOptionKeyMapChange(Int a_option, Int a_keyCode, String a_conflictControl, String a_conflictName)
	If a_option != _openKeyOption
		Return
	EndIf
	If a_keyCode >= 256 && a_keyCode < 266
		ShowMessage("Mouse buttons and wheel are unsupported. Choose a keyboard or gamepad input.", False)
		SetKeyMapOptionValue(a_option, _openKeyCode)
		Return
	EndIf
	If a_keyCode < -1 || a_keyCode >= 282
		ShowMessage("That input is unsupported. Choose a keyboard or gamepad input.", False)
		SetKeyMapOptionValue(a_option, _openKeyCode)
		Return
	EndIf
	If a_conflictControl != ""
		If !ShowMessage("This input is already used by " + a_conflictName + ". Assign it anyway?", True, "$Yes", "$No")
			SetKeyMapOptionValue(a_option, _openKeyCode)
			Return
		EndIf
	EndIf
	If WorldOfDramaLlamaNative.SetOpenKeyCode(a_keyCode)
		_openKeyCode = a_keyCode
		SetKeyMapOptionValue(a_option, _openKeyCode)
	Else
		ShowMessage("That input is unsupported. Choose a keyboard or gamepad input.", False)
		SetKeyMapOptionValue(a_option, _openKeyCode)
	EndIf
EndEvent
