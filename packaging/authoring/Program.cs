using System.Buffers.Binary;
using System.Text.Json;
using Mutagen.Bethesda.Plugins;
using Mutagen.Bethesda.Plugins.Binary;
using Mutagen.Bethesda.Plugins.Records;
using Mutagen.Bethesda.Skyrim;

if (args.Length != 2)
    throw new ArgumentException("Usage: WorldOfDramaLlamaGamePlugin <plugin.esp> <plugin.seq>");

var espPath = Path.GetFullPath(args[0]);
var seqPath = Path.GetFullPath(args[1]);
if (!string.Equals(Path.GetFileName(espPath), "WorldOfDramaLlama.esp", StringComparison.OrdinalIgnoreCase) ||
    !string.Equals(Path.GetFileName(seqPath), "WorldOfDramaLlama.seq", StringComparison.OrdinalIgnoreCase))
    throw new InvalidOperationException("The plugin and sequence must use the namespaced WorldOfDramaLlama filenames.");

Directory.CreateDirectory(Path.GetDirectoryName(espPath)!);
Directory.CreateDirectory(Path.GetDirectoryName(seqPath)!);

var modKey = ModKey.FromNameAndExtension(Path.GetFileName(espPath));
var mod = new SkyrimMod(modKey, SkyrimRelease.SkyrimSE);
mod.ModHeader.Flags = SkyrimModHeader.HeaderFlag.Small;
mod.ModHeader.FormVersion = 44;

var quest = new Quest(new FormKey(modKey, 0x800), SkyrimRelease.SkyrimSE)
{
    EditorID = "WorldOfDramaLlamaMCM"
};
quest.FormVersion = 44;
quest.Flags = Quest.Flag.StartGameEnabled;
quest.Priority = 0;
quest.VirtualMachineAdapter = new QuestAdapter
{
    Version = 5,
    ObjectFormat = 2
};
quest.VirtualMachineAdapter.Scripts.Add(new ScriptEntry { Name = "WorldOfDramaLlamaMCM" });
mod.Quests.Add(quest);
mod.ModHeader.FormID = 0;

var localFormId = quest.FormKey.ID;
if (quest.FormKey.ModKey != modKey || localFormId < 0x800 || localFormId > 0xFFF)
    throw new InvalidOperationException($"Quest FormID {localFormId:X8} is outside the ESL local FormID range.");

mod.WriteToBinary(espPath);

var parsed = SkyrimMod.CreateFromBinary(ModPath.FromPath(espPath), SkyrimRelease.SkyrimSE);
var tes4Header = ReadTes4Header(File.ReadAllBytes(espPath));
var masterCount = tes4Header.MasterCount;
var parsedQuest = parsed.Quests.Single();
var parsedAdapter = parsedQuest.VirtualMachineAdapter ?? throw new InvalidDataException("The QUST has no VMAD adapter.");
var parsedScript = parsedAdapter.Scripts.Single();
var majorRecordCount = parsed.EnumerateMajorRecords().Count();
// HEDR counts this QUST and its top-level GRUP; the TES4 header itself is excluded.
if (!parsed.ModHeader.Flags.HasFlag(SkyrimModHeader.HeaderFlag.Small) ||
    parsed.ModHeader.FormVersion != 44 || parsed.Quests.Count != 1 ||
    parsedQuest.FormKey.ModKey != modKey || parsedQuest.FormKey.ID != localFormId ||
    parsedQuest.EditorID != "WorldOfDramaLlamaMCM" || parsedQuest.Flags != Quest.Flag.StartGameEnabled ||
    parsedAdapter.Version != 5 || parsedAdapter.ObjectFormat != 2 ||
    parsedScript.Name != "WorldOfDramaLlamaMCM" || parsedScript.Properties.Count != 0 ||
    masterCount != 0 ||
    majorRecordCount != 1 ||
    tes4Header.FormId != 0 ||
    tes4Header.RecordCount != 2 ||
    tes4Header.NextFormId != 0x801)
    throw new InvalidDataException("Mutagen read-back did not match the one-quest ESL/VMAD contract.");

Span<byte> sequence = stackalloc byte[sizeof(uint)];
BinaryPrimitives.WriteUInt32LittleEndian(sequence, localFormId);
File.WriteAllBytes(seqPath, sequence.ToArray());

var sequenceReadback = File.ReadAllBytes(seqPath);
if (sequenceReadback.Length != sizeof(uint) ||
    BinaryPrimitives.ReadUInt32LittleEndian(sequenceReadback) != localFormId)
    throw new InvalidDataException("The SEQ read-back did not contain exactly one raw FormID for the own start-game quest.");

var report = new
{
    schemaVersion = 1,
    mutagenAssemblyVersion = typeof(SkyrimMod).Assembly.GetName().Version?.ToString(),
    plugin = Path.GetFileName(espPath),
    pluginHeaderFlags = $"0x{(uint)parsed.ModHeader.Flags:X8}",
    pluginHeaderFormId = $"0x{tes4Header.FormId:X8}",
    pluginFormVersion = parsed.ModHeader.FormVersion,
    masterCount,
    majorRecordCount,
    headerRecordCount = tes4Header.RecordCount,
    headerNextFormId = $"0x{tes4Header.NextFormId:X8}",
    questEditorId = parsedQuest.EditorID,
    questLocalFormId = $"0x{localFormId:X8}",
    questFlags = parsedQuest.Flags.ToString(),
    vmadVersion = parsedAdapter.Version,
    vmadObjectFormat = parsedAdapter.ObjectFormat,
    vmadScriptCount = parsedAdapter.Scripts.Count,
    vmadScript = parsedScript.Name,
    vmadPropertyCount = parsedScript.Properties.Count,
    sequenceEntryCount = sequenceReadback.Length / sizeof(uint),
    sequenceBytes = sequenceReadback.Length,
    sequenceLocalFormId = $"0x{localFormId:X8}",
    sequenceFormat = "Raw little-endian uint32 FormIDs, no count prefix",
    sequenceFormatReference = "xEdit frmViewMain.pas lines 2564-2618: writes FormIDs as raw Cardinal values to wbDataPath + 'Seq\\' (https://raw.githubusercontent.com/TES5Edit/xedit-backup/master/frmViewMain.pas#L2564-L2618)",
    validation = "Mutagen plugin write/read-back passed; SEQ independently matched the xEdit raw-FormID writer format"
};
Console.WriteLine(JsonSerializer.Serialize(report, new JsonSerializerOptions { WriteIndented = true }));

static (uint FormId, uint RecordCount, uint NextFormId, int MasterCount) ReadTes4Header(byte[] plugin)
{
    if (plugin.Length < 24 || System.Text.Encoding.ASCII.GetString(plugin, 0, 4) != "TES4")
        throw new InvalidDataException("The plugin does not start with a TES4 record.");
    var dataSize = BinaryPrimitives.ReadUInt32LittleEndian(plugin.AsSpan(4, 4));
    var end = checked(24 + (int)dataSize);
    if (end > plugin.Length)
        throw new InvalidDataException("The TES4 record length exceeds the plugin file.");

    var masterCount = 0;
    uint? recordCount = null;
    uint? nextFormId = null;
    var offset = 24;
    for (; offset + 6 <= end;)
    {
        var signature = System.Text.Encoding.ASCII.GetString(plugin, offset, 4);
        var subrecordSize = BinaryPrimitives.ReadUInt16LittleEndian(plugin.AsSpan(offset + 4, 2));
        var payloadOffset = offset + 6;
        offset = checked(offset + 6 + subrecordSize);
        if (signature == "MAST") masterCount++;
        if (signature == "HEDR")
        {
            if (subrecordSize != 12 || recordCount.HasValue)
                throw new InvalidDataException("The TES4 header has an invalid or duplicate HEDR subrecord.");
            recordCount = BinaryPrimitives.ReadUInt32LittleEndian(plugin.AsSpan(payloadOffset + 4, 4));
            nextFormId = BinaryPrimitives.ReadUInt32LittleEndian(plugin.AsSpan(payloadOffset + 8, 4));
        }
        if (offset > end)
            throw new InvalidDataException("A TES4 subrecord exceeds the header record.");
    }
    if (offset != end)
        throw new InvalidDataException("The TES4 header has an incomplete subrecord.");
    if (!recordCount.HasValue || !nextFormId.HasValue)
        throw new InvalidDataException("The TES4 header has no HEDR record-count/next-FormID fields.");
    return (
        BinaryPrimitives.ReadUInt32LittleEndian(plugin.AsSpan(12, 4)),
        recordCount.Value,
        nextFormId.Value,
        masterCount);
}
